@extends('layouts.app')

{{-- M02 of a TESTER (S14-04): personal figures in the teal tester shell. An ADMIN gets
     admin/dashboard.blade.php instead; both share x-stat-card and x-recent-runs-table. --}}
@section('title', 'Tổng quan của bạn')

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Tổng quan của bạn</h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Dự án, test case và lần chạy của bạn, cùng trạng thái dịch vụ nền tảng.
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

    <!-- Personal figures -->
    <section aria-labelledby="tester-figures-heading">
        <h2 id="tester-figures-heading" class="sr-only">Số liệu của bạn</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Dự án" icon="folder" tone="teal" data-testid="tester-stat-projects">
                <div class="text-3xl font-extrabold text-white" data-testid="tester-stat-projects-count">{{ $stats['projects_count'] }}</div>
                <p class="mt-1 text-xs text-slate-400">Dự án bạn có quyền truy cập</p>
            </x-stat-card>

            <x-stat-card label="Test case đã duyệt" icon="check-circle" tone="cyan" data-testid="tester-stat-approved">
                <div class="text-3xl font-extrabold text-white">{{ $stats['approved_test_cases'] }}</div>
                <p class="mt-1 text-xs text-slate-400">Sẵn sàng đưa vào lần chạy</p>
            </x-stat-card>

            <x-stat-card label="Tỉ lệ đạt 7 ngày" icon="trending-up" tone="emerald" data-testid="tester-stat-pass-rate">
                <div class="text-3xl font-extrabold text-emerald-400">{{ $stats['pass_rate_7d'] }}</div>
                <p class="mt-1 text-xs text-slate-400">Do bộ kiểm tra assertion tất định quyết định, không do LLM</p>
            </x-stat-card>

            <x-stat-card label="Dịch vụ nền tảng" icon="server" tone="amber" data-testid="tester-stat-services">
                <x-service-status :health="$stats['health']" />
            </x-stat-card>
        </div>
    </section>

    <!-- Quick actions -->
    <section aria-labelledby="tester-actions-heading" class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-800/80 via-slate-800/50 to-teal-950/40 p-5 shadow-xl sm:p-6">
        <h2 id="tester-actions-heading" class="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">Hành động nhanh</h2>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('projects.index', ['create' => 1]) }}" data-testid="quick-create-project" class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-teal-700 px-4 py-2.5 text-xs font-semibold text-white shadow-md shadow-teal-700/25 transition-colors hover:bg-teal-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900">
                <x-shell.icon name="plus" class="h-4 w-4" />
                <span>Tạo dự án mới</span>
            </a>

            <a href="{{ $latestProject ? route('projects.specs.index', $latestProject) : route('projects.index') }}" data-testid="quick-upload-openapi" class="inline-flex min-w-0 max-w-full items-center gap-2 rounded-xl border border-slate-700 bg-slate-800 px-4 py-2.5 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400">
                <x-shell.icon name="upload" class="h-4 w-4" />
                <span class="truncate">Tải lên OpenAPI{{ $latestProject ? ' ('.\Illuminate\Support\Str::limit($latestProject->name, 30).')' : '' }}</span>
            </a>

            @if ($experimentsAvailable)
                <a href="{{ route('experiments.index') }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl border border-slate-700 bg-slate-800 px-4 py-2.5 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400">
                    <x-shell.icon name="chart" class="h-4 w-4 text-emerald-400" />
                    <span>Chạy thực nghiệm</span>
                </a>
            @else
                <span data-testid="experiments-quick-action-disabled" class="rounded-xl border border-dashed border-slate-700 px-4 py-2.5 text-xs text-slate-400">Thực nghiệm không khả dụng trên bản triển khai này</span>
            @endif
        </div>
    </section>

    <!-- Latest runs the tester may see -->
    <x-recent-runs-table :runs="$recentRuns" />
</div>
@endsection
