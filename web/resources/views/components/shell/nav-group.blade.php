{{-- A labelled group of sidebar links (S14-04). --}}
@props(['label', 'id'])
<div {{ $attributes }}>
    <p id="{{ $id }}" class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
    <ul role="list" aria-labelledby="{{ $id }}" class="space-y-0.5">
        {{ $slot }}
    </ul>
</div>
