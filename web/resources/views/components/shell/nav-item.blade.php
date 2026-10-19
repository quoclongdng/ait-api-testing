{{-- One sidebar link of the application shell (S14-04). `active` marks the current screen with
     aria-current="page"; `accent` is the shell colour (indigo: ADMIN, teal: TESTER). --}}
@props(['href', 'active' => false, 'icon' => null, 'accent' => 'indigo', 'testid' => null, 'nested' => false])
@php
    $activeClasses = $accent === 'teal'
        ? 'bg-teal-500/15 text-white ring-1 ring-inset ring-teal-400/30'
        : 'bg-indigo-500/15 text-white ring-1 ring-inset ring-indigo-400/30';
    $activeIcon = $accent === 'teal' ? 'text-teal-300' : 'text-indigo-300';
    $bar = $accent === 'teal' ? 'bg-teal-400' : 'bg-indigo-400';
    $focus = $accent === 'teal' ? 'focus-visible:ring-teal-400' : 'focus-visible:ring-indigo-400';
    $size = $nested ? 'py-1.5 pl-3 pr-2.5 text-[13px]' : 'py-2 px-3 text-sm';
@endphp
<li class="relative">
    @if ($active)
        <span aria-hidden="true" class="absolute -left-3 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full {{ $bar }}"></span>
    @endif
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif @if ($testid) data-testid="{{ $testid }}" @endif
       class="group flex items-center gap-3 rounded-lg font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 {{ $focus }} {{ $size }} {{ $active ? $activeClasses : 'text-slate-300 hover:bg-slate-800/70 hover:text-white' }}">
        @if ($icon)
            <x-shell.icon :name="$icon" class="{{ $nested ? 'h-4 w-4' : 'h-5 w-5' }} shrink-0 {{ $active ? $activeIcon : 'text-slate-400 group-hover:text-slate-200' }}" />
        @endif
        <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>
    </a>
</li>
