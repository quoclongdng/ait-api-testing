@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $classes = match ($value) {
        'COMPLETED', 'SUCCEEDED', 'PASS', 'VALID', 'PARSED' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'RUNNING', 'QUEUED' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
        'PLANNED' => 'border-slate-500/30 bg-slate-500/10 text-slate-300',
        'PARTIAL' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        default => 'border-rose-500/30 bg-rose-500/10 text-rose-300',
    };
    $labels = [
        'PLANNED' => 'Đã lập kế hoạch',
        'RUNNING' => 'Đang chạy',
        'COMPLETED' => 'Hoàn thành',
        'FAILED' => 'Thất bại',
    ];
    // Enums with a Vietnamese label() (§8) render it; the raw code stays in the title.
    $label = $status instanceof \BackedEnum && method_exists($status, 'label')
        ? (string) $status->label()
        : ($labels[$value] ?? $value);
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-semibold {$classes}"]) }} title="{{ \App\Support\EnumTooltip::for($label, $value) }}">{{ $label }}</span>
