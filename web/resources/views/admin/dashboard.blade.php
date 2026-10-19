@extends('layouts.admin')

@section('title', 'Tổng quan quản trị')

@php
    /** @var \App\Data\AdminOverview $overview */
    $passRate = $overview->passRateLast7Days === null
        ? 'N/A'
        : number_format($overview->passRateLast7Days * 100, 1, ',', '.').'%';
    $healthy = $overview->health['status'] === 'ok';
@endphp

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Tổng quan quản trị</h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Số liệu toàn hệ thống: người dùng, dự án, lần chạy, benchmark và dịch vụ nền tảng.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-slate-400">Đang hoạt động:</span>
            <span class="flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200">
                <span aria-hidden="true" class="h-2 w-2 rounded-full bg-emerald-400"></span>
                {{ auth()->user()->name }} ({{ auth()->user()->role->label() }})
            </span>
        </div>
    </div>

    <!-- System-wide figures -->
    <section aria-labelledby="admin-figures-heading">
        <h2 id="admin-figures-heading" class="sr-only">Số liệu toàn hệ thống</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Người dùng" icon="users" tone="indigo" data-testid="admin-stat-users">
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-extrabold text-white" data-testid="admin-stat-users-active">{{ $overview->activeUsers }}</span>
                    <span class="text-xs font-medium text-slate-300">đang hoạt động</span>
                </div>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span class="inline-flex items-center gap-1"><x-shell.icon name="lock" class="h-3.5 w-3.5 text-rose-300" /> <span data-testid="admin-stat-users-locked">{{ $overview->lockedUsers }}</span> bị khóa</span>
                    <span>{{ $overview->adminUsers }} quản trị viên</span>
                </p>
            </x-stat-card>

            <x-stat-card label="Dự án" icon="folder" tone="cyan" data-testid="admin-stat-projects">
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-extrabold text-white" data-testid="admin-stat-projects-count">{{ $overview->projects }}</span>
                    <span class="text-xs font-medium text-slate-300">toàn hệ thống</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">Của mọi kiểm thử viên và quản trị viên</p>
            </x-stat-card>

            <x-stat-card label="Lần chạy 7 ngày" icon="play" tone="emerald" data-testid="admin-stat-runs">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="text-3xl font-extrabold text-white" data-testid="admin-stat-runs-count">{{ $overview->runsLast7Days }}</span>
                    <span class="text-xs font-medium text-slate-300">lần chạy · tỉ lệ đạt <span class="font-semibold text-emerald-400" data-testid="admin-stat-pass-rate">{{ $passRate }}</span></span>
                </div>
                <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span>{{ $overview->completedRunsLast7Days }} hoàn thành</span>
                    <span>{{ $overview->failedRunsLast7Days }} thất bại</span>
                    <span>{{ $overview->activeRuns }} đang chờ/chạy</span>
                </p>
            </x-stat-card>

            <x-stat-card label="Benchmark" icon="cube" tone="amber" data-testid="admin-stat-benchmarks">
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-extrabold text-white" data-testid="admin-stat-benchmarks-count">{{ $overview->benchmarks }}</span>
                    <span class="text-xs font-medium text-slate-300">benchmark</span>
                </div>
                <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span><span data-testid="admin-stat-bugs-count">{{ $overview->benchmarkBugs }}</span> bug cài sẵn</span>
                    <span>{{ $overview->experiments }} thực nghiệm</span>
                </p>
            </x-stat-card>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <!-- Services -->
        <section aria-labelledby="admin-services-heading" data-testid="admin-services" class="rounded-2xl border border-slate-800 bg-slate-800/50 p-5 shadow-lg">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="admin-services-heading" class="whitespace-nowrap text-xs font-bold uppercase tracking-wider text-slate-400">Dịch vụ nền tảng</h2>
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold {{ $healthy ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/30 bg-amber-500/10 text-amber-300' }}">
                    <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full {{ $healthy ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
                    {{ $healthy ? 'Hoạt động bình thường' : 'Cần kiểm tra' }}
                </span>
            </div>
            <x-service-status :health="$overview->health" class="mt-4" />
            <div class="mt-4 flex items-center justify-between border-t border-slate-800 pt-3 text-xs">
                <span class="text-slate-400">Host trong allowlist:</span>
                <a href="{{ route('admin.allowed-targets.index') }}" class="font-semibold text-indigo-300 hover:text-indigo-200">{{ $overview->allowedTargets }} mục do ADMIN thêm</a>
            </div>
        </section>

        <!-- Admin quick actions -->
        <section aria-labelledby="admin-actions-heading" class="rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-800/80 via-slate-800/50 to-indigo-950/40 p-5 shadow-xl lg:col-span-2">
            <h2 id="admin-actions-heading" class="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">Tác vụ quản trị</h2>
            <ul role="list" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @php
                    $tiles = [
                        ['href' => route('admin.users.index'), 'icon' => 'user-plus', 'title' => 'Quản lý người dùng', 'text' => 'Cấp tài khoản, khóa/mở khóa, đổi vai trò.', 'testid' => 'admin-action-users'],
                        ['href' => route('admin.allowed-targets.index'), 'icon' => 'globe', 'title' => 'Cấu hình & allowlist', 'text' => 'Host được phép chạy test, thông tin LLM.', 'testid' => 'admin-action-config'],
                        ['href' => route('admin.benchmarks.index'), 'icon' => 'cube', 'title' => 'Benchmark & ground truth', 'text' => 'Benchmark, bug cài sẵn và fixture xác thực.', 'testid' => 'admin-action-benchmarks'],
                        ['href' => route('projects.index'), 'icon' => 'folder', 'title' => 'Không gian kiểm thử', 'text' => 'Mọi dự án, đặc tả, bộ kiểm thử và lần chạy.', 'testid' => 'admin-action-projects'],
                    ];
                @endphp
                @foreach ($tiles as $tile)
                    <li>
                        <a href="{{ $tile['href'] }}" data-testid="{{ $tile['testid'] }}" class="group flex h-full items-start gap-3 rounded-xl border border-slate-700/70 bg-slate-900/40 p-4 transition-colors hover:border-indigo-400/40 hover:bg-slate-800/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                            <span class="rounded-lg bg-indigo-500/15 p-2 text-indigo-300 ring-1 ring-inset ring-indigo-400/25"><x-shell.icon :name="$tile['icon']" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1 text-sm font-semibold text-white">{{ $tile['title'] }}
                                    <x-shell.icon name="arrow-right" class="h-4 w-4 text-slate-400 transition-transform group-hover:translate-x-0.5 group-hover:text-indigo-300" />
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-400">{{ $tile['text'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 text-xs">
                @if ($experimentsAvailable)
                    <a href="{{ route('experiments.index') }}" class="inline-flex items-center gap-1.5 font-semibold text-indigo-300 hover:text-indigo-200">
                        <x-shell.icon name="chart" class="h-4 w-4" /> Thực nghiệm so sánh RULE/LLM/HYBRID
                    </a>
                @else
                    <span data-testid="experiments-quick-action-disabled" class="text-slate-400">Thực nghiệm không khả dụng trên bản triển khai này</span>
                @endif
            </div>
        </section>
    </div>

    <!-- Latest runs of every project -->
    <x-recent-runs-table :runs="$overview->recentRuns" caption="5 lần chạy gần nhất toàn hệ thống" />
</div>
@endsection
