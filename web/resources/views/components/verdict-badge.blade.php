@props(['verdict'])

@php
    $verdictValue = $verdict instanceof \App\Enums\TestVerdict ? $verdict->value : (string) $verdict;
    $classes = match ($verdictValue) {
        'PASS' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
        'FAIL' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
        'ERROR' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        default => 'bg-slate-700/40 text-slate-300 border-slate-600',
    };
@endphp

{{-- Vietnamese first, stored verdict in parentheses (S14-06 live QA r3, docs/DESIGN.md §8). --}}
<span title="{{ \App\Support\EnumTooltip::for(\App\Support\VerdictLabel::display($verdictValue), $verdictValue) }}" {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded border px-2 py-0.5 text-[11px] font-bold tracking-wide {$classes}"]) }}>{{ \App\Support\VerdictLabel::display($verdictValue) }}</span>
