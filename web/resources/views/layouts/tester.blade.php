{{-- TESTER shell (S14-04): teal accent, "Không gian kiểm thử", no admin links. Shared screens reach it
     through layouts.app when the signed-in user is not an ADMIN. --}}
@php
    $shell = [
        'role' => 'tester',
        'areaLabel' => 'Không gian kiểm thử',
        'sectionLabel' => 'Không gian kiểm thử',
        'areaIcon' => 'sparkles',
        'skip' => 'focus:bg-teal-700',
        'stripe' => 'from-teal-500 via-teal-400 to-cyan-400',
        'glow' => 'bg-teal-600/15',
        'ring' => 'focus-visible:ring-teal-400',
        'chip' => 'border-teal-400/25 bg-teal-500/10',
        'chipIcon' => 'bg-teal-500/20 text-teal-200 ring-1 ring-inset ring-teal-400/30',
        'chipEyebrow' => 'text-teal-300',
        'pill' => 'border-teal-400/30 bg-teal-500/10 text-teal-200',
        'dot' => 'bg-teal-400',
        'avatar' => 'bg-teal-700',
        'roleText' => 'text-teal-300',
        'badge' => 'border-teal-500/30 bg-teal-500/20 text-teal-200',
    ];
    $accent = 'teal';
@endphp
@extends('layouts.base')

@section('sidebar')
    <x-shell.nav-group label="Bắt đầu" id="nav-group-overview" data-testid="nav-group-overview">
        <x-shell.nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="grid" :accent="$accent" testid="nav-dashboard">Tổng quan của bạn</x-shell.nav-item>
    </x-shell.nav-group>

    <x-shell.nav-group label="Kiểm thử API" id="nav-group-workspace" data-testid="nav-group-workspace">
        @include('layouts.partials.workspace-nav', ['accent' => $accent])
    </x-shell.nav-group>

    <x-shell.nav-group label="Nghiên cứu" id="nav-group-research" data-testid="nav-group-research">
        <x-shell.nav-item :href="route('experiments.index')" :active="request()->routeIs('experiments.*')" icon="chart" :accent="$accent" testid="nav-experiments">Thực nghiệm</x-shell.nav-item>
    </x-shell.nav-group>
@endsection
