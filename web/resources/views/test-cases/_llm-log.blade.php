{{-- One LLM call log card of M10 tab 3. Expects $log (App\Models\LlmCallLog). --}}
<div x-data="{ expanded: false }" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            {{-- Provider display name like M02 (S14-06 live QA r3); the raw enum stays in the tooltip. --}}
            <span data-testid="llm-log-provider-model" title="Nhà cung cấp LLM: {{ \App\Support\EnumTooltip::for(\App\Support\LlmDisplay::provider($log->provider), $log->provider) }}" class="rounded bg-purple-500/20 border border-purple-500/30 px-2 py-0.5 text-xs font-bold text-purple-300">
                {{ \App\Support\LlmDisplay::provider($log->provider) ?? '—' }} / {{ $log->model }}
            </span>
            <span class="rounded bg-slate-800 border border-slate-700 px-2 py-0.5 text-[10px] font-mono text-slate-300">
                Mục đích: <span title="{{ \App\Support\EnumTooltip::for($log->purpose->label(), $log->purpose) }}">{{ $log->purpose->label() }}</span>
            </span>
        </div>
        @php
            $tokenTotal = $log->prompt_tokens === null && $log->completion_tokens === null
                ? null
                : ($log->prompt_tokens ?? 0) + ($log->completion_tokens ?? 0);
            // Vietnamese thousands separator, like the percentages of M11 (S14-06).
            $formatCount = fn (?int $value): string => $value === null ? '—' : number_format($value, 0, ',', '.');
        @endphp
        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
            <span>Độ trễ: <strong class="text-slate-200">{{ $formatCount($log->latency_ms) }} ms</strong></span>
            <span>Tokens: <strong class="text-slate-200">{{ $formatCount($tokenTotal) }}</strong> (in: {{ $formatCount($log->prompt_tokens) }}, out: {{ $formatCount($log->completion_tokens) }})</span>
            <span>Nhiệt độ: <strong class="text-slate-200" data-testid="llm-log-temperature">{{ \App\Support\LlmDisplay::temperature($log->temperature) }}</strong></span>
            <span>Seed: <strong class="text-slate-200" data-testid="llm-log-seed">{{ $log->seed ?? '—' }}</strong></span>
        </div>
    </div>

    @if (filled($log->error))
        <div class="llm-log-error rounded-lg border border-rose-500/30 bg-rose-950/30 px-3 py-2 text-xs text-rose-300">
            <span class="font-bold">Lỗi khi gọi LLM:</span> {{ $log->error }}
        </div>
    @endif

    <button
        type="button"
        @click="expanded = !expanded"
        :aria-expanded="expanded.toString()"
        class="text-xs flex items-center gap-1 font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}"
    >
        <span x-text="expanded ? 'Thu gọn prompt và phản hồi' : 'Xem prompt và phản hồi thô'">Xem prompt và phản hồi thô</span>
    </button>

    <div x-show="expanded" x-cloak class="space-y-3 pt-2 border-t border-slate-800">
        <div>
            <span class="text-[11px] font-semibold text-slate-400">Prompt:</span>
            <pre tabindex="0" class="mt-1 rounded-lg bg-slate-950 p-3 text-[11px] font-mono text-slate-300 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">{{ json_encode($log->prompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        <div>
            <span class="text-[11px] font-semibold text-slate-400">Phản hồi thô (raw):</span>
            <pre tabindex="0" class="mt-1 rounded-lg bg-slate-950 p-3 text-[11px] font-mono text-purple-300 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">{{ $log->response_raw }}</pre>
        </div>
    </div>
</div>
