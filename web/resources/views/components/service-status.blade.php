{{-- Web / database / AI service status lines of the dashboards (M02, S14-04), from CheckHealthAction.
     Labels are Vietnamese; the provider enum of ai-service GET /health gets a display name (S14-06). --}}
@props(['health'])
@php
    $aiProvider = $health['checks']['ai_service']['provider'] ?? null;
    $aiProviderLabel = \App\Support\LlmDisplay::provider(is_scalar($aiProvider) ? (string) $aiProvider : null);
@endphp
<div {{ $attributes->merge(['class' => 'space-y-1.5 text-xs']) }}>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">Ứng dụng web:</span>
        <span class="font-semibold text-emerald-400 flex items-center gap-1.5">
            <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Hoạt động
        </span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">Cơ sở dữ liệu:</span>
        @if ($health['checks']['database'])
            <span class="font-semibold text-emerald-400 flex items-center gap-1.5">
                <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Hoạt động
            </span>
        @else
            <span class="font-semibold text-rose-400 flex items-center gap-1.5">
                <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Không kết nối được
            </span>
        @endif
    </div>
    <div class="flex items-center justify-between gap-2">
        <span class="shrink-0 text-slate-400">Dịch vụ AI:</span>
        @if ($health['checks']['ai_service']['reachable'] && $aiProvider === \App\Data\AiServiceStatus::PROVIDER_DISABLED)
            {{-- ADR-0007 public deploy: ai-service is up but only RULE generation is available. --}}
            <span data-testid="dashboard-llm-disabled" class="min-w-0 truncate font-semibold text-slate-300 flex items-center gap-1.5" title="LLM đã tắt, chỉ sinh test RULE">
                <span aria-hidden="true" class="w-1.5 h-1.5 shrink-0 rounded-full bg-slate-400"></span> <span class="truncate">LLM đã tắt (chỉ RULE)</span>
            </span>
        @elseif ($health['checks']['ai_service']['reachable'])
            <span data-testid="dashboard-ai-ok" class="min-w-0 font-semibold text-emerald-400 flex items-center gap-1.5" title="{{ $aiProviderLabel ?? 'Dịch vụ AI' }}">
                <span aria-hidden="true" class="w-1.5 h-1.5 shrink-0 rounded-full bg-emerald-400"></span> <span class="truncate">{{ $aiProviderLabel === null ? 'Hoạt động' : 'Hoạt động ('.$aiProviderLabel.')' }}</span>
            </span>
        @else
            <span class="font-semibold text-amber-400 flex items-center gap-1.5">
                <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Ngoại tuyến
            </span>
        @endif
    </div>
</div>
