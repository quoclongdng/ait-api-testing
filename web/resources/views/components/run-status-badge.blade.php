@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $classes = match ($value) {
        'COMPLETED' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
        'RUNNING' => 'bg-blue-500/10 text-blue-300 border-blue-500/30',
        'QUEUED' => 'bg-slate-700/50 text-slate-300 border-slate-600',
        'FAILED' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
        'CANCELLED' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        default => 'bg-slate-800 text-slate-300 border-slate-700',
    };
    $label = \App\Enums\TestRunStatus::tryFrom($value)?->label() ?? $value;
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-lg border px-2 py-0.5 text-[11px] font-bold tracking-wider $classes"]) }} title="{{ \App\Support\EnumTooltip::for($label, $value) }}">
    {{ $label }}
</span>
