@extends('layouts.app')

@section('title', $testCase->title . ' — Chi tiết test case')

@section('content')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    // M10 lifecycle (§4.2): manual DSL edit, approve, archive via /api/v1. Forms keep their action as a no-JS fallback.
    window.testCaseReview = function (config) {
        return {
            activeTab: 'dsl',
            tabs: ['dsl', 'errors', 'logs'],
            busy: false,
            feedback: { type: null, message: '', errors: [] },
            init() {
                try {
                    const flash = window.sessionStorage.getItem('ait-m10-flash');
                    if (flash) {
                        window.sessionStorage.removeItem('ait-m10-flash');
                        const parsed = JSON.parse(flash);
                        this.show(parsed.type, parsed.message, []);
                    }
                } catch (e) { /* storage unavailable: no flash */ }
            },
            // WAI-ARIA tabs pattern: ArrowLeft/ArrowRight cycle, Home/End jump, the selected tab is the
            // only Tab stop (roving tabindex) and receives focus (S14-06).
            moveTab(step) {
                const index = this.tabs.indexOf(this.activeTab);
                let next;
                if (step === 'first') next = 0;
                else if (step === 'last') next = this.tabs.length - 1;
                else next = (index + step + this.tabs.length) % this.tabs.length;
                this.activeTab = this.tabs[next];
                this.$nextTick(() => {
                    const tab = document.getElementById('tab-' + this.activeTab);
                    if (tab) tab.focus();
                });
            },
            show(type, message, errors) {
                this.feedback = { type: type, message: message, errors: errors || [] };
            },
            // Only field/DSL validation errors carry a list of messages; other codes (CONFLICT…) carry
            // context the message already explains (S14-06).
            flattenErrors(details, code) {
                if (code !== 'VALIDATION_FAILED' && code !== 'DSL_INVALID') return [];
                if (!details || typeof details !== 'object') return [];
                const list = [];
                Object.values(details).forEach((value) => {
                    (Array.isArray(value) ? value : [value]).forEach((item) => {
                        list.push(typeof item === 'string' ? item : JSON.stringify(item));
                    });
                });
                return list;
            },
            syncSavedTitle(form, dsl, title) {
                if (typeof title !== 'string') return;
                form.elements['title'].value = title;
                if (dsl.title !== title) {
                    dsl.title = title;
                    form.elements['dsl'].value = JSON.stringify(dsl, null, 4);
                }
                const header = document.querySelector('[data-testid="tc-title"]');
                if (header) header.textContent = title;
            },
            reloadWith(type, message) {
                try {
                    window.sessionStorage.setItem('ait-m10-flash', JSON.stringify({ type: type, message: message }));
                } catch (e) { /* ignore */ }
                window.location.reload();
            },
            async call(method, url, body) {
                const response = await fetch(url, {
                    method: method,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: body === undefined ? undefined : JSON.stringify(body),
                });
                let payload = null;
                try { payload = await response.json(); } catch (e) { payload = null; }
                return { ok: response.ok, payload: payload };
            },
            async transition(kind) {
                if (this.busy) return;
                if (kind === 'archive' && !window.confirm('Bạn có chắc muốn lưu trữ test case này?')) return;
                this.busy = true;
                try {
                    const result = await this.call('POST', kind === 'approve' ? config.approveUrl : config.archiveUrl);
                    if (result.ok) {
                        this.reloadWith('success', kind === 'approve' ? 'Đã duyệt test case thành công.' : 'Đã lưu trữ test case.');
                        return;
                    }
                    const error = (result.payload && result.payload.error) || {};
                    this.show('error', error.message || 'Không thực hiện được thao tác.', this.flattenErrors(error.details, error.code));
                } catch (e) {
                    this.show('error', 'Không kết nối được máy chủ. Vui lòng thử lại.', []);
                } finally {
                    this.busy = false;
                }
            },
            async save(form) {
                if (this.busy) return;
                let dsl;
                try {
                    dsl = JSON.parse(form.elements['dsl'].value);
                } catch (e) {
                    // The browser's JSON.parse message is English and engine-specific: not shown (S13-03).
                    this.show('error', 'Nội dung DSL không phải JSON hợp lệ. Hãy kiểm tra dấu ngoặc, dấu phẩy và dấu nháy kép.', []);
                    return;
                }
                if (dsl === null || typeof dsl !== 'object' || Array.isArray(dsl)) {
                    this.show('error', 'DSL phải là một đối tượng JSON.', []);
                    return;
                }
                this.busy = true;
                try {
                    const result = await this.call('PATCH', config.updateUrl, { title: form.elements['title'].value, dsl: dsl });
                    if (result.ok) {
                        const data = result.payload.data;
                        if (data.status === 'INVALID') {
                            // The page is not reloaded here: mirror the saved title (title column ==
                            // dsl.title) into the title input, the DSL editor and the header, or the next
                            // save would read the stale input as a title change and revert dsl.title
                            // (S14-06 live QA r7 review).
                            this.syncSavedTitle(form, dsl, data.title);
                            this.show('warning', 'Đã lưu DSL nhưng vẫn còn lỗi cấu trúc/ngữ nghĩa, test case giữ trạng thái INVALID.', data.validation_errors || []);
                            return;
                        }
                        const statusLabel = (config.statusLabels && config.statusLabels[data.status]) || data.status;
                        this.reloadWith('success', 'Đã cập nhật DSL thành công. Trạng thái test case: ' + statusLabel + '.');
                        return;
                    }
                    const error = (result.payload && result.payload.error) || {};
                    this.show('error', error.message || 'Không lưu được DSL.', this.flattenErrors(error.details, error.code));
                } catch (e) {
                    this.show('error', 'Không kết nối được máy chủ. Vui lòng thử lại.', []);
                } finally {
                    this.busy = false;
                }
            },
        };
    };
</script>

<div
    x-data="testCaseReview({
        updateUrl: @js(route('api.v1.test-cases.update', $testCase)),
        approveUrl: @js(route('api.v1.test-cases.approve', $testCase)),
        archiveUrl: @js(route('api.v1.test-cases.archive', $testCase)),
        statusLabels: @js(collect(\App\Enums\TestCaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
    })"
    class="space-y-6"
>

    <!-- Header & Breadcrumbs (M10 Header) -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $suite->project) }}" class="hover:text-white transition">{{ $suite->project->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.show', $suite) }}" class="hover:text-white transition">{{ $suite->name }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">#{{ $testCase->id }}</span>
            </div>
            <h1 class="flex flex-wrap items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span data-testid="tc-title" class="min-w-0 [overflow-wrap:anywhere]">{{ $testCase->title }}</span>
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                <span class="rounded border px-2 py-0.5 font-bold uppercase tracking-wider
                    @if($testCase->endpoint->method->value === 'GET') bg-emerald-500/10 text-emerald-400 border-emerald-500/30
                    @elseif($testCase->endpoint->method->value === 'POST') bg-blue-500/10 text-blue-400 border-blue-500/30
                    @elseif($testCase->endpoint->method->value === 'DELETE') bg-rose-500/10 text-rose-400 border-rose-500/30
                    @else bg-amber-500/10 text-amber-400 border-amber-500/30 @endif">
                    {{ $testCase->endpoint->method->value }}
                </span>
                <span data-testid="tc-endpoint-path" class="min-w-0 max-w-full break-all font-mono text-slate-300">{{ $testCase->endpoint->path }}</span>
                <span>•</span>
                <span class="rounded border px-2 py-0.5 font-semibold
                    @if($testCase->source->value === 'RULE') bg-cyan-500/10 text-cyan-300 border-cyan-500/30
                    @elseif($testCase->source->value === 'LLM') bg-purple-500/10 text-purple-300 border-purple-500/30
                    @elseif($testCase->source->value === 'HYBRID') bg-pink-500/10 text-pink-300 border-pink-500/30
                    @else bg-amber-500/10 text-amber-300 border-amber-500/30 @endif">
                    {{ $testCase->source->value }}
                </span>
                <span>•</span>
                <span class="text-slate-400" title="{{ \App\Support\EnumTooltip::for($testCase->technique->label(), $testCase->technique) }}">{{ $testCase->technique->label() }}</span>
                <span>•</span>
                <span class="rounded border px-2 py-0.5 font-bold uppercase tracking-wider
                    @if($testCase->status->value === 'VALID') bg-emerald-500/10 text-emerald-300 border-emerald-500/30
                    @elseif($testCase->status->value === 'APPROVED') bg-indigo-500/10 text-indigo-300 border-indigo-500/30
                    @elseif($testCase->status->value === 'INVALID') bg-rose-500/10 text-rose-300 border-rose-500/30
                    @elseif($testCase->status->value === 'DUPLICATE') bg-amber-500/10 text-amber-300 border-amber-500/30
                    @else bg-slate-800 text-slate-400 border-slate-700 @endif" title="{{ \App\Support\EnumTooltip::for($testCase->status->label(), $testCase->status) }}">
                    {{ $testCase->status->label() }}
                </span>
            </div>
            <div class="mt-2 text-[11px] font-mono text-slate-400 break-all">
                Dấu vân tay (fingerprint, SHA-256):
                @if (filled($testCase->fingerprint))
                    {{ $testCase->fingerprint }}
                @else
                    <span data-testid="tc-fingerprint-empty" class="font-sans text-slate-400">chưa có (chỉ tính cho test case hợp lệ)</span>
                @endif
            </div>
            @if ($testCase->generation_job_id !== null)
                <div class="mt-1 text-xs text-slate-400">
                    Sinh bởi:
                    <a id="tc-generation-job-link" href="{{ route('generation-jobs.show', $testCase->generation_job_id) }}" class="font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">Tiến trình sinh #{{ $testCase->generation_job_id }}</a>
                </div>
            @endif
        </div>

        <!-- Action Buttons (Approve / Archive) -->
        <div class="flex flex-wrap items-center gap-2">
            @if ($testCase->status->value === 'VALID')
                <form method="POST" action="{{ route('test-cases.approve', $testCase) }}" @submit.prevent="transition('approve')">
                    @csrf
                    <button
                        type="submit"
                        :disabled="busy"
                        data-testid="tc-approve-button"
                        class="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Duyệt test case (Approve)
                    </button>
                </form>
            @endif

            @if (in_array($testCase->status->value, ['VALID', 'APPROVED'], true))
                <form method="POST" action="{{ route('test-cases.archive', $testCase) }}" @submit.prevent="transition('archive')">
                    @csrf
                    <button
                        type="submit"
                        :disabled="busy"
                        class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        Lưu trữ (Archive)
                    </button>
                </form>
            @endif

            <a
                href="{{ route('suites.show', $suite) }}"
                class="rounded-xl border border-slate-700 bg-slate-800/60 px-3.5 py-2.5 text-xs font-semibold text-slate-400 hover:text-white transition"
            >
                Quay lại bộ kiểm thử
            </a>
        </div>
    </div>

    <x-api-feedback />

    <!-- 3 Navigation Tabs (Tab 1: DSL, Tab 2: Validation Errors, Tab 3: LLM Logs) -->
    @php $activeTabClasses = \App\Support\AccentClasses::activeTab(auth()->user()); @endphp
    <div
        role="tablist"
        aria-label="Chi tiết test case"
        @keydown.arrow-right.prevent="moveTab(1)"
        @keydown.arrow-left.prevent="moveTab(-1)"
        @keydown.home.prevent="moveTab('first')"
        @keydown.end.prevent="moveTab('last')"
        class="flex flex-wrap gap-1 border-b border-slate-800 sm:gap-2"
    >
        <button
            type="button"
            role="tab"
            id="tab-dsl"
            aria-controls="tabpanel-dsl"
            :aria-selected="(activeTab === 'dsl').toString()"
            :tabindex="activeTab === 'dsl' ? 0 : -1"
            @click="activeTab = 'dsl'"
            :class="activeTab === 'dsl' ? @js($activeTabClasses) : 'border-transparent text-slate-400 hover:text-slate-200'"
            class="flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-3 text-xs transition rounded-t-lg focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }} sm:px-4 sm:text-sm"
        >
            <svg aria-hidden="true" class="hidden h-4 w-4 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
            <span class="sm:hidden">DSL</span><span class="hidden sm:inline">Tab 1: Trình soạn thảo &amp; trực quan DSL</span>
        </button>

        <button
            type="button"
            role="tab"
            id="tab-errors"
            aria-controls="tabpanel-errors"
            :aria-selected="(activeTab === 'errors').toString()"
            :tabindex="activeTab === 'errors' ? 0 : -1"
            @click="activeTab = 'errors'"
            :class="activeTab === 'errors' ? @js($activeTabClasses) : 'border-transparent text-slate-400 hover:text-slate-200'"
            class="flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-3 text-xs transition rounded-t-lg focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }} sm:px-4 sm:text-sm"
        >
            <svg aria-hidden="true" class="hidden h-4 w-4 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            {{-- Short label below sm keeps the meaning of the desktop label (S14-06 live QA r3). --}}
            <span class="sm:hidden">Lỗi kiểm tra</span><span class="hidden sm:inline">Tab 2: Lỗi kiểm tra hợp lệ</span>
            @if (!empty($testCase->validation_errors))
                {{-- Visual marker only; the accessible name reads "… (có lỗi)" instead of repeating "Lỗi". --}}
                <span data-testid="tc-errors-badge" aria-hidden="true" class="rounded-full bg-rose-500/20 text-rose-400 px-2 py-0.5 text-[10px] font-bold">!</span><span class="sr-only">(có lỗi)</span>
            @endif
        </button>

        <button
            type="button"
            role="tab"
            id="tab-logs"
            aria-controls="tabpanel-logs"
            :aria-selected="(activeTab === 'logs').toString()"
            :tabindex="activeTab === 'logs' ? 0 : -1"
            @click="activeTab = 'logs'"
            :class="activeTab === 'logs' ? @js($activeTabClasses) : 'border-transparent text-slate-400 hover:text-slate-200'"
            class="flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-3 text-xs transition rounded-t-lg focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }} sm:px-4 sm:text-sm"
        >
            <svg aria-hidden="true" class="hidden h-4 w-4 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span class="sm:hidden">Log LLM</span><span class="hidden sm:inline">Tab 3: Log gọi LLM</span>
            @if ($llmLogs->isNotEmpty())
                <span class="rounded-full bg-purple-500/20 text-purple-400 px-2 py-0.5 text-[10px] font-bold">{{ $llmLogs->count() }}</span>
            @endif
        </button>
    </div>

    <!-- TAB 1: DSL Editor & Visualizer -->
    <div x-show="activeTab === 'dsl'" id="tabpanel-dsl" role="tabpanel" aria-labelledby="tab-dsl" class="space-y-6">
        <!-- Structured Visual Steps Card -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                TRỰC QUAN CÁC BƯỚC THỰC THI (TEST STEPS)
            </h2>

            @php
                $steps = $testCase->dsl['steps'] ?? [];
            @endphp

            @if (empty($steps))
                <p class="text-xs text-slate-400">Không có bước thực thi nào trong DSL.</p>
            @else
                <div class="space-y-3">
                    @foreach ($steps as $index => $step)
                        <div class="rounded-xl border border-slate-700/80 bg-slate-900/70 p-4 space-y-3">
                            <div data-testid="tc-step-header" class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                                <span data-testid="dsl-step-title" class="min-w-0 break-words font-semibold text-xs {{ \App\Support\AccentClasses::label(auth()->user()) }}">
                                    Bước {{ $index + 1 }}: {{ \App\Support\StepLabel::display(is_string($step['name'] ?? null) ? $step['name'] : null) ?? 'Yêu cầu HTTP' }}
                                </span>
                                <span data-testid="tc-step-request" class="max-w-full break-all rounded bg-slate-800 px-2 py-0.5 font-mono text-[11px] text-slate-300">
                                    {{ $step['request']['method'] ?? 'GET' }} {{ $step['request']['path'] ?? '/' }}
                                </span>
                            </div>

                            @if (!empty($step['request']['headers']))
                                <div>
                                    <span class="text-[11px] font-semibold text-slate-400">Tiêu đề yêu cầu (headers):</span>
                                    <pre tabindex="0" class="mt-1 rounded-lg bg-slate-950 p-2 text-[11px] font-mono text-slate-300 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">{{ json_encode($step['request']['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif

                            @if (!empty($step['request']['body']))
                                <div>
                                    <span class="text-[11px] font-semibold text-slate-400">Thân yêu cầu (request body):</span>
                                    <pre tabindex="0" class="mt-1 rounded-lg bg-slate-950 p-2 text-[11px] font-mono text-cyan-300 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">{{ json_encode($step['request']['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif

                            @if (!empty($step['assertions']))
                                <div>
                                    <span class="text-[11px] font-semibold text-slate-400">Kiểm tra kỳ vọng (assertions):</span>
                                    {{-- A json_schema assertion is a whole schema object: it gets its own scrollable block
                                         instead of an inline chip, so it never widens the page at 375 px (S14-06). --}}
                                    <div class="mt-1 flex min-w-0 flex-wrap gap-2">
                                        @foreach ($step['assertions'] as $assertion)
                                            @php
                                                $assertionType = is_string($assertion['type'] ?? null) ? $assertion['type'] : 'assertion';
                                                $assertionArgs = is_array($assertion) ? array_diff_key($assertion, ['type' => '']) : [];
                                            @endphp
                                            @if ($assertionType === 'json_schema')
                                                <div data-testid="tc-assertion-block" class="w-full min-w-0 rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1 text-[11px] font-mono text-emerald-300">
                                                    <span>{{ $assertionType }}:</span>
                                                    <pre tabindex="0" class="mt-1 max-w-full overflow-x-auto whitespace-pre text-emerald-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">{{ json_encode($assertionArgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            @else
                                                <span data-testid="tc-assertion-chip" class="max-w-full whitespace-pre-wrap break-all rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1 text-[11px] font-mono text-emerald-300">{{ $assertionType }}: {{ json_encode($assertionArgs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- DSL JSON Editor Form (only INVALID / VALID / APPROVED are editable, §4.2) -->
        @if (in_array($testCase->status->value, ['DUPLICATE', 'ARCHIVED'], true))
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-xs text-slate-400">
            Test case ở trạng thái <span class="font-bold text-slate-200" title="{{ \App\Support\EnumTooltip::for($testCase->status->label(), $testCase->status) }}">{{ $testCase->status->label() }}</span> chỉ được xem, không thể sửa DSL.
        </div>
        @else
        <form method="POST" action="{{ route('test-cases.update', $testCase) }}" @submit.prevent="save($el)" class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            @csrf
            @method('PATCH')

            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h2 class="text-sm font-bold text-white">CHỈNH SỬA DSL TRỰC TIẾP</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Chỉnh sửa DSL sẽ tự động đổi nguồn thành <span class="font-bold text-amber-300">MANUAL</span> và đưa trạng thái về <span class="font-bold text-emerald-300">VALID</span>; test case đã duyệt (APPROVED) sau khi sửa phải được duyệt lại.
                    </p>
                </div>
            </div>

            <div>
                <label for="tc-title" class="mb-1 block text-xs font-semibold text-slate-300">Tiêu đề Test Case <span class="text-rose-400">*</span></label>
                <input
                    id="tc-title"
                    name="title"
                    type="text"
                    required
                    value="{{ old('title', $testCase->title) }}"
                    aria-describedby="tc-title-hint"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >
                <p id="tc-title-hint" data-testid="tc-title-hint" class="mt-1 text-[11px] text-slate-400">
                    Tiêu đề luôn trùng với trường "title" trong DSL: sửa ô này thì "title" trong DSL đổi theo; giữ nguyên ô này thì tiêu đề lấy theo "title" trong DSL.
                </p>
            </div>

            <div>
                @php
                    // Keep the user's edit after a no-JS validation redirect: old('dsl') is the raw
                    // string when the JSON was malformed, or the decoded array when it parsed.
                    $oldDsl = old('dsl');
                    $dslText = is_string($oldDsl)
                        ? $oldDsl
                        : json_encode(is_array($oldDsl) ? $oldDsl : $testCase->dslObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                @endphp
                <label for="tc-dsl-editor" class="mb-1 block text-xs font-semibold text-slate-300">Mã JSON DSL <span class="text-rose-400">*</span></label>
                <textarea
                    id="tc-dsl-editor"
                    name="dsl"
                    rows="16"
                    required
                    class="w-full rounded-xl border border-slate-700 bg-slate-950 p-4 font-mono text-xs text-emerald-400 outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >{{ $dslText }}</textarea>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-800 pt-4">
                <button
                    type="submit"
                    :disabled="busy"
                    data-testid="tc-save-dsl-button"
                    class="rounded-xl px-5 py-2.5 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}"
                >
                    Lưu thay đổi DSL
                </button>
            </div>
        </form>
        @endif
    </div>

    <!-- TAB 2: Validation Errors -->
    <div x-show="activeTab === 'errors'" id="tabpanel-errors" role="tabpanel" aria-labelledby="tab-errors" class="space-y-4">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 pb-3">
                <h2 class="text-sm font-bold text-white">TRẠNG THÁI KIỂM TRA NGỮ NGHĨA & CÚ PHÁP (DSL VALIDATION)</h2>
                <span class="rounded-full border border-slate-700 bg-slate-900/60 px-3 py-1 text-xs font-mono text-slate-300">
                    Số lần tự sửa (Repair attempts): {{ $testCase->repair_attempts }}
                </span>
            </div>

            @if (empty($testCase->validation_errors))
                <div class="rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-4 text-xs text-emerald-300 flex items-center gap-3">
                    <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <p class="font-bold">Không phát hiện lỗi validation</p>
                        <p class="mt-0.5 text-emerald-400">Test case tuân thủ đầy đủ JSON Schema DSL và tính đúng đắn ngữ nghĩa của OpenAPI spec.</p>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-4 space-y-3">
                    <div class="flex items-center gap-2 text-xs font-bold text-rose-300">
                        <svg class="w-5 h-5 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        Phát hiện các lỗi validation sau:
                    </div>
                    @php
                        // Readable list (S13-03); anything that is not a plain message stays as JSON.
                        $validationMessages = collect(is_array($testCase->validation_errors) ? $testCase->validation_errors : [$testCase->validation_errors])
                            ->flatten()
                            ->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    @endphp
                    <ul class="list-disc space-y-1 pl-5 text-xs text-rose-300" data-testid="validation-error-list">
                        @foreach ($validationMessages as $message)
                            <li class="break-words font-mono">{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <!-- TAB 3: LLM Call Logs -->
    <div x-show="activeTab === 'logs'" id="tabpanel-logs" role="tabpanel" aria-labelledby="tab-logs" class="space-y-4">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
            <h2 class="text-sm font-bold text-white border-b border-slate-800 pb-3">
                NHẬT KÝ GỌI MÔ HÌNH NGÔN NGỮ LỚN (LLM)
            </h2>
            <p class="text-xs text-slate-400">Gồm các lời gọi sinh (GENERATE) của tiến trình sinh test và các lời gọi tự sửa (REPAIR) của chính test case này; lời gọi tự sửa của test case khác không hiển thị.</p>

            @if ($llmLogs->isEmpty() && $otherLlmLogs->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center">
                    <p class="text-xs text-slate-400">Không có bản ghi gọi LLM nào cho test case này.</p>
                    <p class="text-[11px] text-slate-400 mt-1">Test case được sinh bằng kỹ thuật RULE hoặc tạo thủ công (MANUAL).</p>
                </div>
            @else
                @if ($llmLogs->isEmpty())
                    <p class="text-xs text-slate-400" data-testid="llm-logs-related-empty">Không xác định được lời gọi LLM đã sinh riêng test case này; xem các lời gọi của cả tiến trình bên dưới.</p>
                @else
                    <p class="text-xs text-slate-400">Các lời gọi LLM đã sinh hoặc tự sửa test case này (endpoint {{ $endpoint ? $endpoint->method->value.' '.$endpoint->path : '—' }}).</p>
                    <div class="space-y-4" data-testid="llm-logs-related">
                        @foreach ($llmLogs as $log)
                            @include('test-cases._llm-log', ['log' => $log])
                        @endforeach
                    </div>
                @endif

                @if ($otherLlmLogs->isNotEmpty())
                    <details class="rounded-xl border border-slate-800 bg-slate-900/40 p-4" data-testid="llm-logs-others">
                        <summary class="cursor-pointer text-xs font-semibold text-slate-300">Các lời gọi khác của tiến trình ({{ $otherLlmLogs->count() }}, cho endpoint khác)</summary>
                        <div class="mt-4 space-y-4">
                            @foreach ($otherLlmLogs as $log)
                                @include('test-cases._llm-log', ['log' => $log])
                            @endforeach
                        </div>
                    </details>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
