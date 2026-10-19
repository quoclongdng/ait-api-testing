@props(['job', 'summary'])

{{-- M11 progress & statistics (S6-04). Initial values are server-rendered; Alpine polls /api/v1 while QUEUED/RUNNING. --}}
@php
    $statusClasses = [
        'SUCCEEDED' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
        'PARTIAL' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'RUNNING' => 'bg-blue-500/10 text-blue-300 border-blue-500/30',
        'QUEUED' => 'bg-slate-700/50 text-slate-300 border-slate-600',
        'FAILED' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
    ];
    $barClasses = [
        'SUCCEEDED' => 'bg-gradient-to-r from-emerald-500 to-teal-400',
        'PARTIAL' => 'bg-amber-500',
        'FAILED' => 'bg-rose-500',
        'RUNNING' => 'bg-indigo-500 animate-pulse',
        'QUEUED' => 'bg-indigo-500 animate-pulse',
    ];
    $statusLabels = [
        'QUEUED' => 'Đang chờ',
        'RUNNING' => 'Đang chạy',
        'SUCCEEDED' => 'Thành công',
        'PARTIAL' => 'Thành công một phần',
        'FAILED' => 'Thất bại',
    ];
    $params = is_array($job->params) ? $job->params : [];
    $counts = $summary['counts'];
    $llm = $summary['llm'];
    // Vietnamese number format ("100,0%"), the same as M13 and M15.
    $pct = static fn (int $part, int $total): string => $total > 0 ? number_format($part * 100 / $total, 1, ',', '.') . '%' : '0%';
    // Counts with the Vietnamese thousands separator ("116.395"), like fmt() below (S14-06).
    $num = static fn (int|float|null $value): string => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
@endphp

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    function generationProgress(config) {
        return {
            ...config,
            timer: null,
            pollError: '',
            init() {
                if (!this.summary.is_terminal) {
                    this.timer = setInterval(() => this.poll(), 2000);
                }
            },
            statusLabel() { return this.statusLabels[this.summary.status] || this.summary.status; },
            // "H:i:s d/m/Y" in APP_DISPLAY_TIMEZONE, like the server-rendered datetime component.
            formatTime(iso) {
                if (!iso) return null;
                const date = new Date(iso);
                if (Number.isNaN(date.getTime())) return null;
                const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
                    timeZone: this.timezone, hour12: false,
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                }).formatToParts(date).map((p) => [p.type, p.value]));
                return `${parts.hour}:${parts.minute}:${parts.second} ${parts.day}/${parts.month}/${parts.year}`;
            },
            // Token and latency counts with the Vietnamese thousands separator, like the server render.
            fmt(n) {
                return n === null || n === undefined ? '—' : Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
            },
            percent(part, total) {
                if (!total) return '0%';
                return (part * 100 / total).toLocaleString('vi-VN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
            },
            stopPolling() {
                if (this.timer) {
                    clearInterval(this.timer);
                    this.timer = null;
                }
            },
            statusClass() { return this.statusClasses[this.summary.status] || 'bg-slate-800 text-slate-300 border-slate-700'; },
            barClass() { return this.barClasses[this.summary.status] || 'bg-indigo-500'; },
            async poll() {
                try {
                    const res = await fetch(this.apiUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        this.pollError = body?.error?.message || 'Không cập nhật được tiến độ.';
                        // 4xx other than 408/429 will not heal by retrying: stop polling (401 → login).
                        if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) {
                            this.stopPolling();
                            if (res.status === 401) {
                                window.location.href = this.loginUrl;
                            }
                        }
                        return;
                    }
                    this.pollError = '';
                    this.summary = body.data.summary;
                    // The start/end time follow the polled job, not only the first render.
                    this.startedAt = body.data.started_at ?? this.startedAt;
                    this.finishedAt = body.data.finished_at ?? this.finishedAt;
                    window.dispatchEvent(new CustomEvent('generation-status', { detail: this.summary.status }));
                    if (this.summary.is_terminal) {
                        this.stopPolling();
                        // Reload once so the generated test case list is rendered server-side.
                        setTimeout(() => window.location.reload(), 800);
                    }
                } catch (e) {
                    this.pollError = 'Mất kết nối tới máy chủ, đang thử lại...';
                }
            },
        };
    }
</script>

<div
    x-data="generationProgress(@js([
        'summary' => $summary,
        'apiUrl' => route('api.v1.generation-jobs.show', $job),
        'statusClasses' => $statusClasses,
        'statusLabels' => $statusLabels,
        'loginUrl' => route('login'),
        'barClasses' => $barClasses,
        'startedAt' => $job->started_at?->toISOString(),
        'finishedAt' => $job->finished_at?->toISOString(),
        'timezone' => (string) config('ait.display_timezone'),
    ]))"
    class="space-y-6"
>
    <!-- Job Status Banner & Progress Bar -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <span
                    id="job-status"
                    role="status"
                    aria-live="polite"
                    :class="statusClass()"
                    x-text="statusLabel()"
                    :title="statusLabel() === summary.status ? summary.status : statusLabel() + ' (' + summary.status + ')'"
                    class="rounded-xl border px-3 py-1 font-bold text-xs uppercase tracking-wider {{ $statusClasses[$summary['status']] ?? '' }}"
                 title="{{ \App\Support\EnumTooltip::for($statusLabels[$summary['status']] ?? null, $summary['status']) }}">{{ $statusLabels[$summary['status']] ?? $summary['status'] }}</span>
                <span class="text-xs text-slate-300 font-medium">
                    Chiến lược: <strong class="text-white">{{ $job->strategy->value }}</strong>
                </span>
                <span class="text-xs text-slate-400 font-mono" x-show="summary.duration_seconds !== null" @if ($summary['duration_seconds'] === null) x-cloak @endif>
                    (Hoàn thành trong <span x-text="summary.duration_seconds">{{ $summary['duration_seconds'] }}</span> giây)
                </span>
                @if ($summary['replayed'] ?? false)
                    {{-- QA S14-06: a replay took no LLM time; the latencies below are those of the recorded calls. --}}
                    <span class="text-xs text-slate-400" data-testid="job-replayed">(Nạp lại từ bản ghi thực nghiệm, không gọi LLM; độ trễ bên dưới là của các lời gọi đã ghi)</span>
                @endif
            </div>

            <div class="text-xs text-slate-400 font-mono">
                Bắt đầu: <span id="job-started-at" x-text="formatTime(startedAt) ?? 'Chưa chạy'"><x-datetime :value="$job->started_at" format="H:i:s d/m/Y" fallback="Chưa chạy" /></span>
                <span x-show="finishedAt" @if (! $job->finished_at) x-cloak @endif>
                    · Kết thúc: <span id="job-finished-at" x-text="formatTime(finishedAt)"><x-datetime :value="$job->finished_at" format="H:i:s d/m/Y" /></span>
                </span>
            </div>
        </div>

        <div class="w-full bg-slate-900 rounded-full h-3 overflow-hidden border border-slate-800">
            <div
                :class="barClass()"
                :style="'width: ' + summary.progress_percent + '%'"
                class="h-3 rounded-full transition-all duration-500 {{ $barClasses[$summary['status']] ?? 'bg-indigo-500' }}"
                style="width: {{ $summary['progress_percent'] }}%"
            ></div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
            <span class="text-slate-300" aria-live="polite" x-text="summary.progress_label">{{ $summary['progress_label'] }}</span>
            <span class="text-slate-400" x-show="!summary.is_terminal" @if ($summary['is_terminal']) x-cloak @endif>
                Tiến trình chạy nền, trang tự động cập nhật mỗi 2 giây. Chỉ hủy được khi tiến trình còn đang chờ.
            </span>
        </div>
        <p x-show="pollError" x-cloak x-text="pollError" role="alert" class="text-xs text-amber-300"></p>

        <div x-show="summary.error_message" @if ($summary['error_message'] === null) x-cloak @endif class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-3 text-xs text-rose-300 space-y-1">
            <p><strong>Lỗi tiến trình:</strong> <span x-text="summary.error_message">{{ $summary['error_message'] }}</span></p>
            <template x-for="(line, idx) in summary.job_errors.length > 1 ? summary.job_errors : []" :key="idx">
                <p class="font-mono" x-text="'• ' + line"></p>
            </template>
        </div>
    </div>

    <!-- Generation KPI Metrics -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 shadow-sm">
            <span class="text-xs text-slate-400 font-medium">Tổng số case sinh ra</span>
            <p class="mt-1 text-2xl font-bold text-white" id="stat-generated" x-text="summary.counts.generated">{{ $counts['generated'] }}</p>
        </div>

        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-4 shadow-sm">
            <span class="text-xs text-emerald-400 font-medium">Hợp lệ (VALID)</span>
            <p class="mt-1 text-2xl font-bold text-emerald-300" id="stat-valid" x-text="summary.counts.valid">{{ $counts['valid'] }}</p>
            <span class="text-[11px] text-emerald-400/80" x-text="percent(summary.counts.valid, summary.counts.generated)">{{ $pct($counts['valid'], $counts['generated']) }}</span>
        </div>

        <div class="rounded-2xl border border-rose-500/30 bg-rose-950/20 p-4 shadow-sm">
            <span class="text-xs text-rose-400 font-medium">Không hợp lệ (INVALID)</span>
            <p class="mt-1 text-2xl font-bold text-rose-300" id="stat-invalid" x-text="summary.counts.invalid">{{ $counts['invalid'] }}</p>
        </div>

        <div class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4 shadow-sm">
            <span class="text-xs text-amber-400 font-medium">Trùng lặp (DUPLICATE)</span>
            <p class="mt-1 text-2xl font-bold text-amber-300" id="stat-duplicates" x-text="summary.counts.duplicates">{{ $counts['duplicates'] }}</p>
        </div>

        <div class="rounded-2xl border border-purple-500/30 bg-purple-950/20 p-4 shadow-sm">
            <span class="text-xs text-purple-400 font-medium">Đã tự sửa (REPAIRED)</span>
            <p class="mt-1 text-2xl font-bold text-purple-300" id="stat-repaired" x-text="summary.counts.repaired">{{ $counts['repaired'] }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Job parameters -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-3">
            <h2 class="text-sm font-bold text-white">THAM SỐ CỦA TIẾN TRÌNH</h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                <dt class="text-slate-400">Chiến lược</dt>
                <dd class="font-mono text-white">{{ $job->strategy->value }}</dd>
                <dt class="text-slate-400">Số endpoint</dt>
                <dd class="font-mono text-white">{{ count($summary['endpoints']) }}</dd>
                <dt class="text-slate-400">Kỹ thuật</dt>
                <dd class="font-mono text-white break-words">{{ is_array($params['techniques'] ?? null) && $params['techniques'] !== [] ? implode(', ', array_filter($params['techniques'], 'is_string')) : 'Mặc định' }}</dd>
                <dt class="text-slate-400">Số case tối đa / endpoint</dt>
                <dd class="font-mono text-white">
                    {{ is_scalar($params['max_cases_per_endpoint'] ?? null) ? $params['max_cases_per_endpoint'] : 10 }}
                    @if ($job->strategy->value !== 'RULE')
                        <span id="llm-cases-cap-hint" class="block font-sans text-[11px] text-amber-300">Giá trị yêu cầu; mỗi endpoint LLM chỉ sinh tối đa số case nhỏ hơn giữa giá trị này và giới hạn của máy chủ AI (mặc định 3).</span>
                    @endif
                </dd>
                @if ($job->strategy->value !== 'RULE')
                    <dt class="text-slate-400">Mô hình (model)</dt>
                    <dd class="font-mono text-white">{{ is_string($params['model'] ?? null) && $params['model'] !== '' ? $params['model'] : 'Mặc định của hệ thống' }}</dd>
                    <dt class="text-slate-400">Nhiệt độ (temperature)</dt>
                    <dd class="font-mono text-white">{{ \App\Support\LlmDisplay::temperature(is_numeric($params['temperature'] ?? null) ? $params['temperature'] : 0.2) }}</dd>
                    <dt class="text-slate-400">Seed</dt>
                    <dd class="font-mono text-white">{{ is_numeric($params['seed'] ?? null) ? $params['seed'] : 'Mặc định của hệ thống' }}</dd>
                    <dt class="text-slate-400">Số lần tự sửa tối đa</dt>
                    <dd class="font-mono text-white">{{ is_numeric($params['max_repair_attempts'] ?? null) ? $params['max_repair_attempts'] : 2 }}</dd>
                @endif
            </dl>
        </div>

        <!-- LLM usage -->
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-3">
            <h2 class="text-sm font-bold text-white">SỬ DỤNG LLM (TOKEN &amp; ĐỘ TRỄ)</h2>
            @if ($job->strategy->value === 'RULE')
                <p class="text-xs text-slate-400">Chiến lược RULE không gọi LLM.</p>
            @else
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                    <dt class="text-slate-400">Số lời gọi LLM</dt>
                    <dd class="font-mono text-white"><span x-text="summary.llm.calls">{{ $llm['calls'] }}</span> (sinh <span x-text="summary.llm.generate_calls">{{ $llm['generate_calls'] }}</span>, repair <span x-text="summary.llm.repair_calls">{{ $llm['repair_calls'] }}</span>, lỗi <span x-text="summary.llm.failed_calls">{{ $llm['failed_calls'] }}</span>)</dd>
                    <dt class="text-slate-400">Token đầu vào (prompt)</dt>
                    <dd class="font-mono text-white" id="llm-prompt-tokens" x-text="fmt(summary.llm.prompt_tokens)">{{ $num($llm['prompt_tokens']) }}</dd>
                    <dt class="text-slate-400">Token đầu ra (completion)</dt>
                    <dd class="font-mono text-white" id="llm-completion-tokens" x-text="fmt(summary.llm.completion_tokens)">{{ $num($llm['completion_tokens']) }}</dd>
                    <dt class="text-slate-400">Tổng số token</dt>
                    <dd class="font-mono text-white" id="llm-total-tokens" x-text="fmt(summary.llm.total_tokens)">{{ $num($llm['total_tokens']) }}</dd>
                    <dt class="text-slate-400">Tổng độ trễ</dt>
                    <dd class="font-mono text-white"><span id="llm-total-latency" x-text="fmt(summary.llm.total_latency_ms)">{{ $num($llm['total_latency_ms']) }}</span> ms</dd>
                    <dt class="text-slate-400">Độ trễ trung bình</dt>
                    <dd class="font-mono text-white"><span id="llm-avg-latency" x-text="fmt(summary.llm.avg_latency_ms)">{{ $num($llm['avg_latency_ms']) }}</span> ms / lời gọi</dd>
                    <dt class="text-slate-400">Mô hình đã dùng</dt>
                    <dd class="font-mono text-white" x-text="summary.llm.models.length ? summary.llm.models.join(', ') : '—'">{{ $llm['models'] !== [] ? implode(', ', $llm['models']) : '—' }}</dd>
                </dl>
            @endif
        </div>
    </div>

    <!-- Per-endpoint statistics & errors -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/30 p-5 shadow-xl space-y-3">
        <h2 class="text-sm font-bold text-white">KẾT QUẢ THEO ENDPOINT</h2>
        @if ($summary['endpoints'] === [])
            <p class="text-xs text-slate-400">Tiến trình không có endpoint nào.</p>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table data-testid="generation-endpoints-table" class="w-full min-w-[48rem] text-left text-xs text-slate-300">
                    <thead class="bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5">Endpoint</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right">Sinh ra</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right" title="Hợp lệ (VALID)">Hợp lệ</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right" title="Không hợp lệ (INVALID)">Không hợp lệ</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right" title="Trùng lặp (DUPLICATE)">Trùng lặp</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right">Đã sửa</th>
                            <th class="min-w-[14rem] px-3 py-2.5">Lỗi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($summary['endpoints'] as $row)
                            <tr class="{{ $row['has_error'] ? 'bg-rose-950/20' : '' }}">
                                <td class="px-3 py-2.5 font-mono whitespace-nowrap">
                                    <span class="font-bold text-slate-200">{{ $row['method'] }}</span> {{ $row['path'] }}
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ $row['generated'] }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-emerald-300">{{ $row['valid'] }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-rose-300">{{ $row['invalid'] }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-amber-300">{{ $row['duplicates'] }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-purple-300">{{ $row['repaired'] }}</td>
                                <td class="px-3 py-2.5 text-rose-300">
                                    @foreach ($row['errors'] as $message)
                                        <p class="break-words">• {{ $message }}</p>
                                    @endforeach
                                    @if (($row['note'] ?? null) !== null)
                                        <p data-testid="generation-endpoint-note" class="break-words text-amber-300">{{ $row['note'] }}</p>
                                    @elseif ($row['errors'] === [])
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
