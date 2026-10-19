{{-- ADMIN shell (S14-04): indigo accent, "Khu vực quản trị". Admin screens (admin/*) extend this
     layout; shared screens reach it through layouts.app when the signed-in user is an ADMIN. --}}
@php
    $shell = [
        'role' => 'admin',
        'areaLabel' => 'Khu vực quản trị',
        // Top-bar pill: the sidebar section of the current screen (shared workspace screens such as
        // projects or runs sit under "Không gian kiểm thử" in the admin sidebar too).
        'sectionLabel' => request()->routeIs('dashboard', 'admin.*', 'experiments.*') ? 'Khu vực quản trị' : 'Không gian kiểm thử',
        'areaIcon' => 'shield',
        'skip' => 'focus:bg-indigo-600',
        'stripe' => 'from-indigo-500 via-indigo-400 to-cyan-400',
        'glow' => 'bg-indigo-600/15',
        'ring' => 'focus-visible:ring-indigo-400',
        'chip' => 'border-indigo-400/25 bg-indigo-500/10',
        'chipIcon' => 'bg-indigo-500/20 text-indigo-200 ring-1 ring-inset ring-indigo-400/30',
        'chipEyebrow' => 'text-indigo-300',
        'pill' => 'border-indigo-400/30 bg-indigo-500/10 text-indigo-200',
        'dot' => 'bg-indigo-400',
        'avatar' => 'bg-indigo-600',
        'roleText' => 'text-indigo-300',
        'badge' => 'border-indigo-500/30 bg-indigo-500/20 text-indigo-200',
    ];
    $accent = 'indigo';
@endphp
@extends('layouts.base')

@section('sidebar')
    <x-shell.nav-group label="Quản trị" id="nav-group-admin" data-testid="nav-group-admin">
        <x-shell.nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="grid" :accent="$accent" testid="nav-dashboard">Tổng quan quản trị</x-shell.nav-item>
        <x-shell.nav-item :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')" icon="users" :accent="$accent" testid="nav-admin-users">Quản lý người dùng</x-shell.nav-item>
        <x-shell.nav-item :href="route('admin.allowed-targets.index')" :active="request()->routeIs('admin.allowed-targets.*')" icon="globe" :accent="$accent" testid="nav-admin-config">Cấu hình &amp; allowlist</x-shell.nav-item>
        <x-shell.nav-item :href="route('admin.benchmarks.index')" :active="request()->routeIs('admin.benchmarks.*')" icon="cube" :accent="$accent" testid="nav-admin-benchmarks">Benchmark</x-shell.nav-item>
        <x-shell.nav-item :href="route('experiments.index')" :active="request()->routeIs('experiments.*')" icon="chart" :accent="$accent" testid="nav-experiments">Thực nghiệm</x-shell.nav-item>
    </x-shell.nav-group>

    <x-shell.nav-group label="Không gian kiểm thử" id="nav-group-workspace" data-testid="nav-group-workspace">
        @include('layouts.partials.workspace-nav', ['accent' => $accent])
    </x-shell.nav-group>
@endsection
