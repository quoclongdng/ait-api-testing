@extends('layouts.app')

@section('title', 'Cấu hình sinh test — ' . $suite->name)

@php
    $techniqueLabels = [
        'HAPPY_PATH' => 'Luồng chuẩn (Happy Path)',
        'BOUNDARY' => 'Giá trị biên (Boundary)',
        'EQUIVALENCE' => 'Phân vùng tương đương (Equivalence)',
        'MISSING_REQUIRED' => 'Thiếu trường bắt buộc (Missing Required)',
        'TYPE_MISMATCH' => 'Sai kiểu dữ liệu (Type Mismatch)',
        'INVALID_INPUT' => 'Đầu vào không hợp lệ (Invalid Input)',
        'AUTH' => 'Xác thực & phân quyền (Auth)',
        'BUSINESS_RULE' => 'Ràng buộc nghiệp vụ (Business Rule)',
        'SEQUENCE' => 'Chuỗi nghiệp vụ nhiều bước (Sequence)',
    ];
    $ruleTechniques = ['HAPPY_PATH', 'BOUNDARY', 'EQUIVALENCE', 'MISSING_REQUIRED', 'TYPE_MISMATCH', 'INVALID_INPUT'];
    $defaultTechniques = [
        'RULE' => $ruleTechniques,
        'LLM' => \App\Data\Generation\GenerationJobInput::DEFAULT_LLM_TECHNIQUES,
        'HYBRID' => \App\Data\Generation\GenerationJobInput::DEFAULT_HYBRID_TECHNIQUES,
    ];

    // ADR-0007: without an LLM on this server only RULE can be chosen.
    $llmDisabled = ($llmUnavailableReason ?? null) !== null;
    $oldStrategy = in_array(old('strategy'), $llmDisabled ? ['RULE'] : ['RULE', 'LLM', 'HYBRID'], true) ? old('strategy') : 'RULE';
    $oldTechniques = is_array(old('techniques')) ? array_values(array_filter(old('techniques'), 'is_string')) : null;
    $oldEndpointIds = is_array(old('endpoint_ids'))
        ? array_values(array_map('strval', array_filter(old('endpoint_ids'), 'is_numeric')))
        : $endpoints->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

    $formConfig = [
        'strategy' => $oldStrategy,
        'llmDisabled' => $llmDisabled,
        'techniques' => $oldTechniques ?? $defaultTechniques[$oldStrategy],
        'defaultTechniques' => $defaultTechniques,
        'ruleTechniques' => $ruleTechniques,
        'endpointIds' => $oldEndpointIds,
        'allEndpointIds' => $endpoints->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        'maxCasesPerEndpoint' => is_numeric(old('max_cases_per_endpoint')) ? (int) old('max_cases_per_endpoint') : $defaults['max_cases_per_endpoint'],
        // Empty by default so model = null is sent and ai-service uses its own AI_LLM_MODEL.
        'model' => is_string(old('model')) ? old('model') : '',
        'temperature' => is_numeric(old('temperature')) ? (float) old('temperature') : $defaults['temperature'],
        'seed' => is_numeric(old('seed')) ? (int) old('seed') : $defaults['seed'],
        'maxRepairAttempts' => is_numeric(old('max_repair_attempts')) ? (int) old('max_repair_attempts') : $defaults['max_repair_attempts'],
        'apiUrl' => route('api.v1.suites.generation-jobs.store', $suite),
        'showUrlTemplate' => route('generation-jobs.show', ['generationJob' => '__ID__']),
    ];
@endphp

@section('content')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    function generationForm(config) {
        return {
            ...config,
            submitting: false,
            errorMessage: '',
            errorDetails: [],
            get usesLlm() { return this.strategy !== 'RULE'; },
            techniqueEnabled(key) { return this.usesLlm || this.ruleTechniques.includes(key); },
            selectStrategy(value) {
                if (this.strategy === value || (this.llmDisabled && value !== 'RULE')) return;
                this.strategy = value;
                this.techniques = [...this.defaultTechniques[value]];
            },
            get allSelected() { return this.endpointIds.length === this.allEndpointIds.length; },
            toggleAllEndpoints(checked) { this.endpointIds = checked ? [...this.allEndpointIds] : []; },
            payload() {
                // Empty numeric fields are omitted (not coerced to 0 by Number('')) so the server
                // applies its defaults (GenerationJobInput) instead of silently storing 0.
                const isBlank = (v) => v === '' || v === null || v === undefined || String(v).trim() === '';
                const params = {
                    endpoint_ids: (this.endpointIds.length ? this.endpointIds : this.allEndpointIds).map(Number),
                    techniques: this.techniques.filter((t) => this.techniqueEnabled(t)),
                };
                if (!isBlank(this.maxCasesPerEndpoint)) params.max_cases_per_endpoint = Number(this.maxCasesPerEndpoint);
                if (this.usesLlm) {
                    params.model = String(this.model || '').trim() || null;
                    if (!isBlank(this.temperature)) params.temperature = Number(this.temperature);
                    params.seed = isBlank(this.seed) ? null : Number(this.seed);
                    if (!isBlank(this.maxRepairAttempts)) params.max_repair_attempts = Number(this.maxRepairAttempts);
                }
                return { strategy: this.strategy, params };
            },
            async submit() {
                if (this.submitting) return;
                this.submitting = true;
                this.errorMessage = '';
                this.errorDetails = [];
                try {
                    const res = await fetch(this.apiUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify(this.payload()),
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        this.errorMessage = body?.error?.code === 'VALIDATION_FAILED'
                            ? 'Dữ liệu cấu hình không hợp lệ.'
                            : (body?.error?.message || 'Không tạo được tiến trình sinh test.');
                        const details = body?.error?.details || {};
                        this.errorDetails = Object.values(details).flat().filter((m) => typeof m === 'string');
                        this.submitting = false;
                        return;
                    }
                    // Keep the button disabled until the browser leaves the page: a second click
                    // during navigation must not create a duplicate GenerationJob.
                    window.location.href = this.showUrlTemplate.replace('__ID__', body.data.id);
                } catch (e) {
                    this.errorMessage = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                    this.submitting = false;
                }
            },
        };
    }
</script>

<div x-data="generationForm(@js($formConfig))" class="space-y-6">

    <!-- Header & Breadcrumbs (M11 Header) -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $project) }}" class="hover:text-white transition">{{ $project->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.show', $suite) }}" class="hover:text-white transition">{{ $suite->name }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">Sinh test</span>
            </div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Cấu hình sinh test</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Thiết lập chiến lược, phạm vi endpoint và tham số để tự động sinh test case cho bộ kiểm thử <strong class="text-white">{{ $suite->name }}</strong>.
            </p>
        </div>

        <a
            href="{{ route('suites.show', $suite) }}"
            class="rounded-xl border border-slate-700 bg-slate-800/60 px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white transition"
        >
            Hủy và quay lại bộ kiểm thử
        </a>
    </div>

    <!-- API error (envelope §6) -->
    <div x-show="errorMessage" x-cloak role="alert" aria-live="assertive" class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-4 text-xs text-rose-300 space-y-1">
        <p class="font-semibold" x-text="errorMessage"></p>
        <template x-for="detail in errorDetails" :key="detail">
            <p x-text="'• ' + detail"></p>
        </template>
    </div>

    {{-- No-JS validation errors are rendered once by layouts.app. --}}

    <!-- Main Generation Form (fetch → /api/v1; plain POST is the no-JS fallback) -->
    <form method="POST" action="{{ route('suites.generation-jobs.store', $suite) }}" @submit.prevent="submit()" class="space-y-6">
        @csrf
        <input type="hidden" name="strategy" :value="strategy" value="{{ $oldStrategy }}">

        <!-- 1. Chiến lược sinh test (Strategy) -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">1</span>
                CHỌN CHIẾN LƯỢC SINH TEST (STRATEGY)
            </h2>

            @php
                // Full class names (no interpolation) so Tailwind can detect them.
                $strategies = [
                    'RULE' => [
                        'label' => 'RULE-BASED',
                        'text' => 'text-cyan-300',
                        'badge' => 'bg-cyan-500/20 text-cyan-300',
                        'active' => 'border-cyan-500 bg-cyan-950/30 ring-2 ring-cyan-500/40',
                        'description' => 'Sinh theo quy tắc từ OpenAPI: Happy path, thiếu trường, sai kiểu, giá trị biên, phân vùng tương đương. Tất định, không gọi LLM.',
                    ],
                    'LLM' => [
                        'label' => 'LLM-BASED',
                        'text' => 'text-indigo-300',
                        'badge' => 'bg-indigo-500/20 text-indigo-300',
                        'active' => 'border-indigo-500 bg-indigo-950/30 ring-2 ring-indigo-500/40',
                        'description' => 'Gửi prompt có cấu trúc (endpoint, schema, business rules) tới LLM để sinh case; mọi case được kiểm tra DSL, tự sửa (repair) và khử trùng.',
                    ],
                    'HYBRID' => [
                        'label' => 'HYBRID (LAI)',
                        'text' => 'text-purple-300',
                        'badge' => 'bg-purple-500/20 text-purple-300',
                        'active' => 'border-purple-500 bg-purple-950/30 ring-2 ring-purple-500/40',
                        'description' => 'Lấy các case RULE của endpoint làm hạt giống, LLM bổ sung kịch bản nghiệp vụ, chuỗi nhiều bước, phân quyền và biên khó.',
                    ],
                ];
            @endphp

            @if ($llmDisabled)
                <p role="status" data-testid="llm-disabled" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs leading-5 text-amber-200">{{ $llmUnavailableReason }}</p>
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                @foreach ($strategies as $value => $card)
                    @php $strategyDisabled = $llmDisabled && $value !== 'RULE'; @endphp
                    <button
                        type="button"
                        id="strategy-{{ strtolower($value) }}"
                        @click="selectStrategy(@js($value))"
                        :aria-pressed="strategy === @js($value)"
                        :class="strategy === @js($value) ? @js($card['active']) : 'border-slate-700 bg-slate-900/40 hover:border-slate-500'"
                        class="text-left flex flex-col justify-between rounded-xl border p-4 transition select-none{{ $strategyDisabled ? ' cursor-not-allowed opacity-50' : '' }}"
                        @if ($strategyDisabled) disabled aria-disabled="true" title="{{ $llmUnavailableReason }}" @endif
                    >
                        <div class="flex items-center justify-between mb-2 w-full">
                            <span class="font-bold text-sm {{ $card['text'] }}">{{ $card['label'] }}</span>
                            <span x-show="strategy === @js($value)" class="rounded px-1.5 py-0.5 text-[10px] font-bold {{ $card['badge'] }}">Đang chọn</span>
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">{{ $card['description'] }}</p>
                    </button>
                @endforeach
            </div>
        </div>

        <!-- 2. Chọn kỹ thuật kiểm thử (Techniques) -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">2</span>
                CHỌN KỸ THUẬT KIỂM THỬ (TEST TECHNIQUES)
            </h2>
            <p class="text-[11px] text-slate-400" x-show="!usesLlm">RULE chỉ hỗ trợ 6 kỹ thuật luật; AUTH, BUSINESS_RULE và SEQUENCE cần LLM hoặc HYBRID.</p>
            {{-- No technique selected = the strategy's default set (GenerationJobInput), as for endpoints. --}}
            <p
                data-testid="technique-default-hint"
                class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-200"
                x-show="techniques.filter((t) => techniqueEnabled(t)).length === 0"
                x-cloak
            >
                Không chọn kỹ thuật nào: hệ thống dùng bộ kỹ thuật mặc định của chiến lược
                <span x-text="strategy"></span> (<span x-text="defaultTechniques[strategy].join(', ')"></span>).
            </p>
            <noscript>
                <p class="text-[11px] text-slate-400">Không chọn kỹ thuật nào thì hệ thống dùng bộ kỹ thuật mặc định của chiến lược: RULE ({{ implode(', ', $defaultTechniques['RULE']) }}), LLM ({{ implode(', ', $defaultTechniques['LLM']) }}), HYBRID ({{ implode(', ', $defaultTechniques['HYBRID']) }}).</p>
            </noscript>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($techniqueLabels as $techKey => $techLabel)
                    <label
                        :class="techniqueEnabled('{{ $techKey }}') ? 'hover:bg-slate-800/80 cursor-pointer' : 'opacity-40 cursor-not-allowed'"
                        class="flex items-start gap-2.5 rounded-xl border border-slate-700/80 bg-slate-900/60 p-3 text-xs text-slate-300 transition"
                    >
                        <input
                            type="checkbox"
                            name="techniques[]"
                            value="{{ $techKey }}"
                            x-model="techniques"
                            :disabled="!techniqueEnabled('{{ $techKey }}')"
                            @checked(in_array($techKey, $formConfig['techniques'], true))
                            data-testid="technique-checkbox"
                            class="rounded border-slate-700 bg-slate-800 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} h-4 w-4 mt-0.5 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}"
                        >
                        <div>
                            <span class="font-semibold block text-white">{{ $techKey }}</span>
                            <span class="text-[11px] text-slate-400">{{ $techLabel }}</span>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="max-w-xs">
                <label for="max-cases" class="mb-1 block text-xs font-semibold text-slate-300">Số case tối đa mỗi endpoint (1 – 100)</label>
                <input id="max-cases" name="max_cases_per_endpoint" type="number" min="1" max="100" x-model="maxCasesPerEndpoint" value="{{ $formConfig['maxCasesPerEndpoint'] }}" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                <p id="llm-cases-cap-note" x-show="usesLlm" x-cloak class="mt-1 text-[11px] text-amber-300">
                    LLM/HYBRID: máy chủ AI chỉ yêu cầu LLM sinh tối đa một số case cố định mỗi endpoint (mặc định 3), nên số case LLM thực tế mỗi endpoint thường không vượt quá số nhỏ hơn giữa giá trị này và giới hạn đó. HYBRID cộng thêm các case RULE làm hạt giống.
                </p>
            </div>
        </div>

        <!-- 3. Phạm vi Endpoints (Endpoint Scope) -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-white flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">3</span>
                    CHỌN PHẠM VI ENDPOINT KIỂM THỬ ({{ $endpoints->count() }} endpoint trong đặc tả)
                </h2>

                <label class="flex items-center gap-2 text-xs font-semibold cursor-pointer {{ \App\Support\AccentClasses::accentText(auth()->user()) }}">
                    <input
                        type="checkbox"
                        :checked="allSelected"
                        @change="toggleAllEndpoints($event.target.checked)"
                        data-testid="endpoint-select-all"
                        class="rounded border-slate-700 bg-slate-800 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} h-4 w-4 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}"
                    >
                    <span>Chọn tất cả</span>
                </label>
            </div>
            <p class="text-[11px] text-slate-400">Không chọn endpoint nào nghĩa là sinh cho toàn bộ endpoint của đặc tả.</p>

            @if ($endpoints->isEmpty())
                <div class="p-4 rounded-xl border border-amber-500/30 bg-amber-950/20 text-xs text-amber-300">
                    Chưa có endpoint nào được trích xuất từ đặc tả OpenAPI này.
                </div>
            @else
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 max-h-72 overflow-y-auto pr-1">
                    @foreach ($endpoints as $endpoint)
                        @php
                            $methodColor = match ($endpoint->method->value) {
                                'GET' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                'POST' => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
                                'PUT' => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                'PATCH' => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
                                'DELETE' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                default => 'bg-slate-800 text-slate-300 border-slate-700',
                            };
                        @endphp
                        <label class="flex items-center gap-3 rounded-xl border border-slate-700/60 bg-slate-900/60 p-2.5 text-xs text-slate-300 hover:bg-slate-800/80 cursor-pointer transition">
                            <input
                                type="checkbox"
                                name="endpoint_ids[]"
                                value="{{ $endpoint->id }}"
                                x-model="endpointIds"
                                @checked(in_array((string) $endpoint->id, $formConfig['endpointIds'], true))
                                class="endpoint-checkbox rounded border-slate-700 bg-slate-800 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} h-4 w-4 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}"
                            >
                            <span class="rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $methodColor }}">
                                {{ $endpoint->method->value }}
                            </span>
                            <span class="font-mono text-slate-200 truncate flex-1" title="{{ $endpoint->path }}">
                                {{ $endpoint->path }}
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- 4. Tham số LLM (chỉ LLM & HYBRID) -->
        <div x-show="usesLlm" x-cloak class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">4</span>
                THAM SỐ LLM (CHO CHIẾN LƯỢC LLM &amp; HYBRID)
            </h2>
            <p class="text-[11px] text-slate-400">
                Các tham số được lưu vào job và gửi nguyên văn tới dịch vụ AI. Để trống model để dùng model mặc định của hệ thống. PASS/FAIL vẫn chỉ do bộ kiểm tra assertion tất định quyết định, không do LLM.
            </p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="ai-model" class="mb-1 block text-xs font-semibold text-slate-300">Mô hình LLM (model)</label>
                    <input id="ai-model" name="model" type="text" maxlength="100" list="ai-model-options" x-model="model" :disabled="!usesLlm" value="{{ $formConfig['model'] }}" placeholder="Mặc định của hệ thống" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                    {{-- Model of the configured provider (ai-service /health), not fixed Ollama names. --}}
                    <datalist id="ai-model-options">
                        @foreach ($modelSuggestions ?? [] as $suggestedModel)
                            <option value="{{ $suggestedModel }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label for="ai-temperature" class="mb-1 block text-xs font-semibold text-slate-300">Nhiệt độ (temperature: 0 – 2)</label>
                    <input id="ai-temperature" name="temperature" type="number" step="0.05" min="0" max="2" x-model="temperature" :disabled="!usesLlm" value="{{ $formConfig['temperature'] }}" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                </div>

                <div>
                    <label for="ai-seed" class="mb-1 block text-xs font-semibold text-slate-300">Seed tái lập kết quả</label>
                    <input id="ai-seed" name="seed" type="number" min="0" x-model="seed" :disabled="!usesLlm" value="{{ $formConfig['seed'] }}" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                </div>

                <div>
                    <label for="ai-repairs" class="mb-1 block text-xs font-semibold text-slate-300">Số lần tự sửa tối đa (max repair: 0 – 10)</label>
                    <input id="ai-repairs" name="max_repair_attempts" type="number" min="0" max="10" x-model="maxRepairAttempts" :disabled="!usesLlm" value="{{ $formConfig['maxRepairAttempts'] }}" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                </div>
            </div>
        </div>

        <!-- 5. Business rules gửi kèm (LLM & HYBRID) -->
        <div x-show="usesLlm" x-cloak class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-white flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">5</span>
                    RÀNG BUỘC NGHIỆP VỤ GỬI KÈM ({{ $businessRules->count() }})
                </h2>
                <a href="{{ route('projects.business-rules.index', $project) }}" class="text-xs font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">Quản lý quy tắc nghiệp vụ (business rules) →</a>
            </div>
            <p class="text-[11px] text-slate-400">
                Rule chung của dự án và rule gắn với endpoint đã chọn được đưa vào prompt.
                <span x-show="strategy === 'HYBRID'">Với HYBRID, các case RULE hợp lệ của endpoint (hoặc case RULE sinh tạm nếu suite chưa có) được dùng làm hạt giống.</span>
            </p>

            @if ($businessRules->isEmpty())
                <div class="p-3 rounded-xl border border-slate-700 bg-slate-900/40 text-xs text-slate-400">
                    Dự án chưa có business rule nào. LLM chỉ dựa vào đặc tả OpenAPI.
                </div>
            @else
                <ul class="space-y-2 max-h-56 overflow-y-auto pr-1">
                    @foreach ($businessRules as $rule)
                        <li
                            class="rounded-xl border border-slate-700/60 bg-slate-900/60 p-2.5 text-xs text-slate-300"
                            @if ($rule->endpoint_id !== null) x-show="endpointIds.length === 0 || endpointIds.includes('{{ $rule->endpoint_id }}')" @endif
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-semibold text-indigo-300">{{ $rule->code }}</span>
                                <span class="text-[10px] text-slate-400">
                                    {{ $rule->endpoint ? $rule->endpoint->method->value . ' ' . $rule->endpoint->path : 'Toàn dự án' }}
                                </span>
                            </div>
                            <p class="mt-1 text-slate-400">{{ $rule->description }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end gap-3 pt-2">
            <a href="{{ route('suites.show', $suite) }}" class="rounded-xl border border-slate-700 px-5 py-2.5 text-xs font-semibold text-slate-300 hover:text-white transition">
                Hủy bỏ
            </a>
            <button
                id="btn-start-generation"
                type="submit"
                :disabled="submitting"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 via-indigo-500 to-cyan-500 px-6 py-2.5 text-xs font-bold text-white shadow-lg shadow-indigo-500/25 hover:brightness-110 transition disabled:opacity-50"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span x-text="submitting ? 'Đang tạo tiến trình...' : 'Bắt đầu tiến trình sinh test'">Bắt đầu tiến trình sinh test</span>
            </button>
        </div>
    </form>
</div>
@endsection
