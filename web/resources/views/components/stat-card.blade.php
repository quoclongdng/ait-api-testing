{{-- One figure card of the dashboards (M02, S14-04): an uppercase eyebrow label, an outline icon in a
     tinted chip and the figure itself in the slot. Shared by the ADMIN and TESTER dashboards so both
     use the same markup; `tone` picks the icon colour. --}}
@props(['label', 'icon', 'tone' => 'indigo'])
@php
    $toneClasses = match ($tone) {
        'teal' => 'border-teal-500/20 bg-teal-500/10 text-teal-300',
        'cyan' => 'border-cyan-500/20 bg-cyan-500/10 text-cyan-300',
        'emerald' => 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300',
        'amber' => 'border-amber-500/20 bg-amber-500/10 text-amber-300',
        default => 'border-indigo-500/20 bg-indigo-500/10 text-indigo-300',
    };
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col rounded-2xl border border-slate-800 bg-slate-800/50 p-5 shadow-lg']) }}>
    <div class="flex items-center justify-between gap-3">
        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ $label }}</span>
        <span class="shrink-0 rounded-xl border p-2 {{ $toneClasses }}"><x-shell.icon :name="$icon" /></span>
    </div>
    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
