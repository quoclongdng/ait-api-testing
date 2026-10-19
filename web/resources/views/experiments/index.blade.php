@extends('layouts.app')

@section('title', 'Thực nghiệm')

@php
    $strategies = ['RULE' => 'RULE-BASED', 'LLM' => 'LLM', 'HYBRID' => 'HYBRID'];
    $hasActive = collect($experiments->items())->contains(fn ($e) => in_array($e->status->value, ['PLANNED', 'RUNNING'], true));
@endphp

@section('content')
<div
    x-data="experimentPage({
        storeUrl: @js(route('api.v1.experiments.store')),
        defaults: @js($defaults),
        hasActive: @js($hasActive),
        firstBenchmarkId: @js($benchmarks->first()?->id),
    })"
    class="space-y-6"
>
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Thực nghiệm</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">So sánh hiệu quả sinh test tự động giữa Rule-based, LLM và Hybrid theo các chỉ số đánh giá (trung bình ± độ lệch chuẩn qua các lần lặp).</p>
        </div>
        @if ($unavailableReason === null)
            <button id="btn-create-experiment" type="button" @click="formOpen = !formOpen" class="{{ \App\Support\AccentClasses::headerCta(auth()->user()) }}"><span aria-hidden="true" class="text-base leading-none">+</span> Tạo thực nghiệm</button>
        @endif
    </div>

    @if ($unavailableReason !== null)
        <p role="status" data-testid="experiments-disabled" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs leading-5 text-amber-200">{{ $unavailableReason }}</p>
    @endif

    {{-- Cấu hình thực nghiệm --}}
    @if ($unavailableReason === null)
    <section x-show="formOpen" x-cloak class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl">
        <h2 class="text-sm font-bold text-white">CẤU HÌNH THỰC NGHIỆM</h2>
        <p class="mt-1 text-xs text-slate-400">Mỗi chiến lược được chọn tạo một thực nghiệm riêng cùng tên để so sánh trong một bảng. Thực nghiệm chạy nền qua hàng đợi.</p>

        @if ($benchmarks->isEmpty())
            <p class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200">Chưa có benchmark nào. Quản trị viên cần đồng bộ ground truth tại màn hình Benchmark.</p>
        @else
            <form class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3" @submit.prevent="submit()">
                <label class="block text-xs md:col-span-2">
                    <span class="font-semibold text-slate-300">Tên thực nghiệm</span>
                    <input type="text" :disabled="batchLocked" x-model="form.name" required maxlength="255" placeholder="Ví dụ: So sánh benchmark #1 - đợt 1" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} focus:outline-none">
                </label>
                <label class="block text-xs">
                    <span class="font-semibold text-slate-300">Benchmark</span>
                    <select :disabled="batchLocked" x-model.number="form.benchmark_id" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100">
                        @foreach ($benchmarks as $benchmark)
                            <option value="{{ $benchmark->id }}">{{ $benchmark->name }} ({{ $benchmark->version }}) — {{ $benchmark->bugs_count }} bug</option>
                        @endforeach
                    </select>
                </label>
                <fieldset class="text-xs md:col-span-3">
                    <legend class="font-semibold text-slate-300">Chiến lược so sánh</legend>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($strategies as $value => $label)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-900/60 px-3 py-2">
                                <input type="checkbox" value="{{ $value }}" :disabled="queuedStrategies.includes(@js($value))" x-model="form.strategies" class="rounded border-slate-600 bg-slate-800 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}">
                                <span class="font-mono text-slate-200">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="block text-xs">
                    <span class="font-semibold text-slate-300">Model LLM</span>
                    <input type="text" :disabled="batchLocked" x-model="form.model" maxlength="100" placeholder="Mặc định của hệ thống" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100">
                </label>
                <label class="block text-xs">
                    <span class="font-semibold text-slate-300">Seed lần lặp 1 (các lần sau theo giao thức 42, 1024, 2026)</span>
                    <input type="number" :disabled="batchLocked" x-model.number="form.seed" min="0" required class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100">
                </label>
                <label class="block text-xs">
                    <span class="font-semibold text-slate-300">Temperature</span>
                    <input type="number" :disabled="batchLocked" x-model.number="form.temperature" min="0" max="2" step="0.1" required class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100">
                </label>
                <label class="block text-xs">
                    <span class="font-semibold text-slate-300">Số lần lặp (3–10)</span>
                    <input type="number" :disabled="batchLocked" x-model.number="form.repetitions" min="3" max="10" required class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100">
                </label>
                <div class="flex items-end md:col-span-2">
                    <button type="submit" :disabled="submitting || reloading" class="rounded-xl px-4 py-2.5 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 disabled:opacity-50 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}">
                        <span x-show="!submitting && !reloading">▶ Chạy thực nghiệm</span>
                        <span x-show="submitting || reloading" x-cloak>Đang gửi…</span>
                    </button>
                </div>
            </form>
            <p x-show="error" x-cloak x-text="error" role="alert" aria-live="assertive" class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-200"></p>
            <p x-show="message" x-cloak x-text="message" role="status" aria-live="polite" class="mt-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-200"></p>
        @endif
    </section>
    @endif

    {{-- Bảng so sánh RULE / LLM / HYBRID --}}
    @forelse ($comparisons as $group)
        <section class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
            <div class="border-b border-slate-800 px-5 py-4">
                <h2 class="text-sm font-bold text-white">BẢNG SO SÁNH CHỈ SỐ — {{ $group['name'] }}</h2>
                <p class="mt-1 text-xs text-slate-400">
                    Benchmark: <span class="text-slate-300">{{ $group['benchmark']->name }} ({{ $group['benchmark']->version }})</span>
                    · Lặp lại: {{ $group['config']['repetitions'] ?? '—' }} lần
                    · Seed: {{ implode(', ', array_map('strval', (array) ($group['config']['seeds'] ?? [$group['config']['seed'] ?? '—']))) }}
                    · Nhiệt độ: {{ \App\Support\LlmDisplay::temperature($group['config']['temperature'] ?? null) }}
                    · Mô hình: {{ $group['config']['model'] ?? 'mặc định của hệ thống' }}
                </p>
            </div>
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                        <tr>
                            <th class="px-5 py-3">Chỉ số đánh giá</th>
                            @foreach ($strategies as $value => $label)
                                <th class="px-5 py-3">
                                    {{ $label }}
                                    @if ($group['experiments'][$value])
                                        <a href="{{ route('experiments.show', $group['experiments'][$value]) }}" class="ml-1 normal-case {{ \App\Support\AccentClasses::link(auth()->user()) }}">#{{ $group['experiments'][$value]->id }}</a>
                                        <x-status-badge :status="$group['experiments'][$value]->status" class="ml-1 normal-case" />
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-300">
                        @foreach ($group['rows'] as $row)
                            <tr class="hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-medium text-slate-200">{{ $row['label'] }}</td>
                                @foreach ($strategies as $value => $label)
                                    <td class="px-5 py-3">
                                        <x-metric-value :mean="$row['cells'][$value]['mean']" :stddev="$row['cells'][$value]['stddev']" :format="$row['format']" />
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (collect($group['bug_matrix'])->contains(fn ($bug) => collect($bug['cells'])->filter()->isNotEmpty()))
                <div class="border-t border-slate-800 px-5 py-4">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">Ma trận phát hiện bug theo từng lần lặp</h3>
                    <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative mt-3 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-400">
                                <tr>
                                    <th class="py-1.5 pr-4">Mã bug</th>
                                    <th class="py-1.5 pr-4">Phân loại</th>
                                    @foreach ($strategies as $value => $label)
                                        <th class="py-1.5 pr-4">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="font-mono text-slate-300">
                                @foreach ($group['bug_matrix'] as $bug)
                                    <tr>
                                        <td data-testid="matrix-bug-code" class="whitespace-nowrap py-1 pr-4 text-cyan-300">{{ $bug['code'] }}</td>
                                        <td class="py-1 pr-4 text-slate-400" title="{{ \App\Support\EnumTooltip::for(\App\Enums\BenchmarkBugCategory::tryFrom((string) $bug['category'])?->label(), (string) $bug['category']) }}">{{ \App\Enums\BenchmarkBugCategory::tryFrom((string) $bug['category'])?->label() ?? $bug['category'] }}</td>
                                        @foreach ($strategies as $value => $label)
                                            <td class="whitespace-nowrap py-1 pr-4">
                                                @if ($bug['cells'][$value] === null)
                                                    <span class="text-slate-400">—</span>
                                                @else
                                                    @foreach ($bug['cells'][$value] as $hit)
                                                        <span class="{{ $hit ? 'text-emerald-400' : 'text-rose-400' }}" title="{{ $hit ? 'Phát hiện' : 'Không phát hiện' }}" aria-hidden="true">{{ $hit ? '✓' : '✗' }}</span><span class="sr-only">Lần {{ $loop->iteration }}: {{ $hit ? 'Phát hiện' : 'Không phát hiện' }}</span>
                                                    @endforeach
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @empty
        {{-- One empty state only: the list below is not rendered while there is no experiment. --}}
        @if ($experiments->total() === 0)
            <section data-testid="experiments-empty" class="rounded-2xl border border-dashed border-slate-700 p-10 text-center text-sm text-slate-400">
                @if ($unavailableReason === null)
                    Chưa có thực nghiệm nào. Bấm “Tạo thực nghiệm” để so sánh RULE, LLM và HYBRID trên một benchmark.
                @else
                    <span data-testid="experiments-empty-disabled">Chưa có thực nghiệm nào. Tạo thực nghiệm không khả dụng trên bản triển khai này; thực nghiệm đầy đủ được chạy trên môi trường cục bộ của nhóm nghiên cứu.</span>
                @endif
            </section>
        @endif
    @endforelse

    {{-- Danh sách thực nghiệm --}}
    @if ($experiments->total() > 0)
    <section data-testid="experiments-list" class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
            <h2 class="text-sm font-bold text-white">DANH SÁCH THỰC NGHIỆM</h2>
            <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">{{ $experiments->total() }} thực nghiệm</span>
        </div>
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                    <tr>
                        <th class="px-5 py-3">ID</th>
                        <th class="px-5 py-3">Tên</th>
                        <th class="px-5 py-3">Benchmark</th>
                        <th class="px-5 py-3">Chiến lược</th>
                        <th class="px-5 py-3">Tiến độ</th>
                        <th class="px-5 py-3">Trạng thái</th>
                        <th class="px-5 py-3">Tỉ lệ phát hiện bug (BDR)</th>
                        <th class="px-5 py-3 text-right">Chi tiết</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-300">
                    @forelse ($experiments as $experiment)
                        <tr class="hover:bg-slate-800/30">
                            <td class="px-5 py-3 font-mono text-slate-400">#{{ $experiment->id }}</td>
                            <td class="px-5 py-3 font-medium text-slate-200">{{ $experiment->name }}</td>
                            <td class="px-5 py-3">{{ $experiment->benchmark->name }}</td>
                            <td class="px-5 py-3 font-mono">{{ $experiment->strategy->value }}</td>
                            <td class="px-5 py-3 font-mono">{{ count($experiment->repetitions_data ?? []) }}/{{ $experiment->config['repetitions'] ?? '?' }} lần lặp</td>
                            <td class="px-5 py-3"><x-status-badge :status="$experiment->status" /></td>
                            <td class="px-5 py-3">
                                <x-metric-value :mean="$experiment->metrics['bug_detection_rate'] ?? null" :stddev="$experiment->metrics['stddev']['bug_detection_rate'] ?? null" />
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('experiments.show', $experiment) }}" data-testid="experiment-view-link" class="rounded-lg border px-3 py-1.5 font-semibold {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}">Xem</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-slate-400">Chưa có thực nghiệm nào.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($experiments->hasPages())
            <div class="border-t border-slate-800 px-5 py-3 text-xs">{{ $experiments->links() }}</div>
        @endif
    </section>
    @endif
</div>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    function experimentPage(options) {
        return {
            formOpen: false,
            submitting: false,
            error: '',
            message: '',
            form: {
                name: '',
                benchmark_id: options.firstBenchmarkId,
                strategies: ['RULE', 'LLM', 'HYBRID'],
                // Empty => model = null: ai-service uses its own AI_LLM_MODEL.
                model: '',
                seed: options.defaults.seed,
                temperature: options.defaults.temperature,
                repetitions: 3,
            },
            // True after part of a multi-strategy batch was created: the shared config is frozen so a retry
            // only sends the remaining strategies under the same name/benchmark (grouping of the comparison table).
            batchLocked: false,
            queuedStrategies: [],
            reloading: false,
            init() {
                if (options.hasActive) {
                    // Auto-refresh progress only while the user is not editing or submitting the form.
                    setInterval(() => {
                        if (this.canAutoReload()) {
                            window.location.reload();
                        }
                    }, 10000);
                }
            },
            canAutoReload() {
                return !this.formOpen && !this.submitting && !this.batchLocked && !this.reloading;
            },
            async submit() {
                if (this.submitting || this.reloading) {
                    return;
                }
                this.error = '';
                this.message = '';
                if (this.form.strategies.length === 0) {
                    this.error = 'Hãy chọn ít nhất một chiến lược.';
                    return;
                }
                this.submitting = true;
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const pending = [...this.form.strategies];
                try {
                    for (const strategy of pending) {
                        let response;
                        try {
                            response = await fetch(options.storeUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': token,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({
                                    name: this.form.name,
                                    benchmark_id: this.form.benchmark_id,
                                    strategy: strategy,
                                    config: {
                                        model: this.form.model || null,
                                        seed: this.form.seed,
                                        temperature: this.form.temperature,
                                        repetitions: this.form.repetitions,
                                    },
                                }),
                            });
                        } catch (e) {
                            this.reportPartialFailure(strategy, 'Không kết nối được máy chủ.');
                            return;
                        }
                        const json = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            // Only VALIDATION_FAILED details are field messages; other codes carry context (S14-06).
                            const details = (json.error && json.error.code === 'VALIDATION_FAILED' && json.error.details) ? Object.values(json.error.details).flat().map(String) : [];
                            const reason = (json.error && json.error.message) ? json.error.message : 'Không thể tạo thực nghiệm.';
                            this.reportPartialFailure(strategy, details.length > 0 ? reason + ' ' + details.join(' ') : reason);
                            return;
                        }
                        this.queuedStrategies.push(strategy);
                        this.form.strategies = this.form.strategies.filter((s) => s !== strategy);
                        this.batchLocked = true;
                    }
                    this.message = 'Đã đưa thực nghiệm vào hàng đợi (' + this.queuedStrategies.join(', ') + '). Trang sẽ tự cập nhật tiến độ.';
                    this.reloading = true;
                    setTimeout(() => window.location.reload(), 1200);
                } finally {
                    this.submitting = false;
                }
            },
            reportPartialFailure(strategy, reason) {
                this.error = 'Chiến lược ' + strategy + ' chưa được tạo: ' + reason;
                if (this.queuedStrategies.length > 0) {
                    this.message = 'Đã đưa vào hàng đợi: ' + this.queuedStrategies.join(', ')
                        + '. Bấm "Chạy thực nghiệm" để thử lại chỉ với: ' + this.form.strategies.join(', ') + '.';
                }
            },
        };
    }
</script>
@endsection
