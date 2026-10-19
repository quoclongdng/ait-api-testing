@props(['mean' => null, 'stddev' => null, 'format' => 'rate'])

@php
    $formatValue = function (float|int $value) use ($format): string {
        return match ($format) {
            // One rounding rule with summary.md / the report (QA S14-06).
            'rate' => \App\Support\MetricFormat::percent($value).'%',
            'ms' => \App\Support\MetricFormat::number($value / 1000, 2).' giây',
            default => number_format($value, 0, ',', '.'),
        };
    };
@endphp

@if ($mean === null)
    <span {{ $attributes->merge(['class' => 'text-slate-400']) }}>—</span>
@else
    <span {{ $attributes->merge(['class' => 'font-mono text-slate-100']) }}>
        {{ $formatValue($mean) }}@if ($stddev !== null)<span class="text-slate-400"> ± {{ $formatValue($stddev) }}</span>@endif
    </span>
@endif
