@extends('layouts.app')

@section('title', $suite->name . ' — Bộ kiểm thử')

@section('content')
@php
    $primaryButton = \App\Support\AccentClasses::primaryButton(auth()->user());
    $filtersActive = $filters['search'] !== '' || $filters['source'] !== '' || $filters['technique'] !== '' || $filters['status'] !== '';
@endphp
<div x-data="{
    selectedIds: [],
    selectAll: false,
    toggleAll(totalOnPage) {
        if (this.selectAll) {
            this.selectedIds = Array.from(document.querySelectorAll('.tc-checkbox')).map(el => el.value);
        } else {
            this.selectedIds = [];
        }
    }
}" class="space-y-6">

    <!-- Header & Breadcrumbs (M09 Header) -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $suite->project) }}" class="hover:text-white transition">{{ $suite->project->name }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ $suite->name }}</span>
            </div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>{{ $suite->name }}</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Đặc tả: <span class="text-cyan-400 font-mono">{{ $suite->apiSpec->version_label }}</span> ({{ $suite->apiSpec->source_filename }}) · {{ $suite->apiSpec->endpoints->count() }} endpoint · Tạo bởi {{ $suite->creator->name }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <a
                id="btn-generate-tests"
                href="{{ route('suites.generation-jobs.create', $suite) }}"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:brightness-110"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Sinh test mới
            </a>

            <a
                id="btn-run-tests"
                href="{{ route('suites.runs.index', $suite) }}"
                class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Chạy test suite
            </a>
        </div>
    </div>

    <!-- Summary KPI Stats Cards -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 shadow-sm">
            <span class="text-xs text-slate-400 font-medium">Tổng số case</span>
            <p class="mt-1 text-2xl font-bold text-white">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-4 shadow-sm">
            <span class="text-xs text-emerald-400 font-medium">Hợp lệ (VALID)</span>
            <p class="mt-1 text-2xl font-bold text-emerald-300">{{ $stats['valid'] }}</p>
        </div>
        <div data-testid="suite-stat-approved" class="rounded-2xl border p-4 shadow-sm {{ \App\Support\AccentClasses::statCard(auth()->user()) }}">
            <span class="text-xs font-medium {{ \App\Support\AccentClasses::statCardLabel(auth()->user()) }}">Đã duyệt (APPROVED)</span>
            <p class="mt-1 text-2xl font-bold {{ \App\Support\AccentClasses::statCardValue(auth()->user()) }}">{{ $stats['approved'] }}</p>
        </div>
        <div class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4 shadow-sm">
            <span class="text-xs text-amber-400 font-medium">Trùng lặp (DUPLICATE)</span>
            <p class="mt-1 text-2xl font-bold text-amber-300">{{ $stats['duplicate'] }}</p>
        </div>
        <div class="rounded-2xl border border-rose-500/30 bg-rose-950/20 p-4 shadow-sm">
            <span class="text-xs text-rose-400 font-medium">Không hợp lệ (INVALID)</span>
            <p class="mt-1 text-2xl font-bold text-rose-300">{{ $stats['invalid'] }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700/60 bg-slate-900/40 p-4 shadow-sm">
            <span class="text-xs text-slate-400 font-medium">Lưu trữ (ARCHIVED)</span>
            <p class="mt-1 text-2xl font-bold text-slate-300">{{ $stats['archived'] }}</p>
        </div>
    </div>

    <!-- Recent Runs (entry point to M13 report) -->
    <section id="recent-runs" class="rounded-2xl border border-slate-800 bg-slate-800/30 overflow-hidden shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
            <h2 class="text-sm font-semibold text-white">Lần chạy gần đây</h2>
            <span class="text-[11px] text-slate-400">Nhấn vào lần chạy để xem báo cáo</span>
        </div>
        @if ($recentRuns->isEmpty())
            <p class="px-4 py-6 text-center text-xs text-slate-400">Bộ kiểm thử này chưa có lần chạy nào.</p>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">Lần chạy</th>
                            <th class="px-4 py-2.5">Hệ thống đích</th>
                            <th class="px-4 py-2.5">Trạng thái</th>
                            <th class="px-4 py-2.5">Kết quả</th>
                            <th class="px-4 py-2.5">Bắt đầu</th>
                            <th class="px-4 py-2.5 text-right">Báo cáo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($recentRuns as $run)
                            @php
                                $runTotals = is_array($run->totals) ? $run->totals : [];
                            @endphp
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('runs.report', $run) }}" class="font-mono font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">#{{ $run->id }}</a>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-slate-400">{{ $run->target_base_url }}</td>
                                <td class="px-4 py-2.5">
                                    <x-run-status-badge :status="$run->status" />
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($runTotals !== [])
                                        <span class="text-emerald-400">{{ (int) ($runTotals['pass'] ?? 0) }} Đạt</span>
                                        · <span class="text-rose-400">{{ (int) ($runTotals['fail'] ?? 0) }} Không đạt</span>
                                        · <span class="text-amber-400">{{ (int) ($runTotals['error'] ?? 0) }} Lỗi</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-400"><x-datetime :value="$run->started_at ?? $run->created_at" /></td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('runs.report', $run) }}" data-testid="suite-run-report-link" class="font-medium {{ \App\Support\AccentClasses::link(auth()->user()) }}">Xem báo cáo</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <!-- Recent Generation Jobs (entry point to M11 progress & statistics) -->
    <section id="recent-generation-jobs" class="rounded-2xl border border-slate-800 bg-slate-800/30 overflow-hidden shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-4 py-3">
            <h2 class="text-sm font-semibold text-white">Tiến trình sinh test gần đây</h2>
            <span class="text-[11px] text-slate-400">Nhấn vào tiến trình để xem tiến độ và thống kê</span>
        </div>
        @if ($recentGenerationJobs->isEmpty())
            <p class="px-4 py-6 text-center text-xs text-slate-400">Bộ kiểm thử này chưa có tiến trình sinh test nào.</p>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">Tiến trình</th>
                            <th class="px-4 py-2.5">Chiến lược</th>
                            <th class="px-4 py-2.5">Trạng thái</th>
                            <th class="px-4 py-2.5">Thời gian tạo</th>
                            <th class="px-4 py-2.5 text-right">Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($recentGenerationJobs as $job)
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('generation-jobs.show', $job) }}" class="font-mono font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">#{{ $job->id }}</a>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-slate-300">{{ $job->strategy->value }}</td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :status="$job->status" />
                                </td>
                                <td class="px-4 py-2.5 text-slate-400"><x-datetime :value="$job->created_at" /></td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('generation-jobs.show', $job) }}" class="font-medium {{ \App\Support\AccentClasses::link(auth()->user()) }}">Xem tiến trình</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <!-- Filter Form Bar -->
    <form method="GET" action="{{ route('suites.show', $suite) }}" class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Search -->
            <div>
                <label for="filter-search" class="block text-xs font-medium text-slate-400 mb-1">Tìm kiếm từ khóa / fingerprint</label>
                <input
                    id="filter-search"
                    name="search"
                    type="search"
                    value="{{ $filters['search'] }}"
                    placeholder="Tìm theo tiêu đề, hash..."
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2 text-xs text-white placeholder-slate-500 outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >
            </div>

            <!-- Source Filter -->
            <div>
                <label for="filter-source" class="block text-xs font-medium text-slate-400 mb-1">Nguồn (Source)</label>
                <select
                    id="filter-source"
                    name="source"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2 text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >
                    <option value="">-- Tất cả nguồn --</option>
                    @foreach (\App\Enums\TestCaseSource::cases() as $src)
                        <option value="{{ $src->value }}" {{ $filters['source'] === $src->value ? 'selected' : '' }}>
                            {{ $src->value }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Technique Filter -->
            <div>
                <label for="filter-technique" class="block text-xs font-medium text-slate-400 mb-1">Kỹ thuật (Technique)</label>
                <select
                    id="filter-technique"
                    name="technique"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2 text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >
                    <option value="">-- Tất cả kỹ thuật --</option>
                    @foreach (\App\Enums\TestTechnique::cases() as $tech)
                        <option value="{{ $tech->value }}" {{ $filters['technique'] === $tech->value ? 'selected' : '' }}>
                            {{ $tech->label() }} ({{ $tech->value }})
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <label for="filter-status" class="block text-xs font-medium text-slate-400 mb-1">Trạng thái (Status)</label>
                <select
                    id="filter-status"
                    name="status"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2 text-xs text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
                >
                    <option value="">-- Tất cả trạng thái --</option>
                    @foreach (\App\Enums\TestCaseStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ $filters['status'] === $st->value ? 'selected' : '' }}>
                            {{ $st->label() }} ({{ $st->value }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2 border-t border-slate-800/80">
            <span class="text-xs text-slate-400">Hiển thị {{ $testCases->total() }} test case phù hợp</span>
            <div class="flex gap-2">
                @if ($filtersActive)
                    <a href="{{ route('suites.show', $suite) }}" class="rounded-xl border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 hover:bg-slate-800 transition">
                        Đặt lại bộ lọc
                    </a>
                @endif
                <button type="submit" class="rounded-xl px-4 py-1.5 text-xs font-semibold transition shadow-sm focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">
                    Lọc dữ liệu
                </button>
            </div>
        </div>
    </form>

    <!-- Bulk Actions Form Toolbar -->
    <form id="bulk-action-form" method="POST" action="{{ route('suites.bulk-action', $suite) }}" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-3 flex flex-wrap items-center justify-between gap-3">
        @csrf
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                <input
                    type="checkbox"
                    x-model="selectAll"
                    @change="toggleAll({{ $testCases->count() }})"
                    class="rounded border-slate-700 bg-slate-800 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} h-4 w-4 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}"
                >
                <span>Chọn tất cả trang này</span>
            </label>
            <span x-show="selectedIds.length > 0" x-cloak class="text-xs font-semibold {{ \App\Support\AccentClasses::accentText(auth()->user()) }}">
                (Đã chọn <span x-text="selectedIds.length"></span> test case)
            </span>
        </div>

        <template x-for="id in selectedIds" :key="id">
            <input type="hidden" name="test_case_ids[]" :value="id">
        </template>

        <div class="flex items-center gap-2">
            <button
                type="submit"
                name="action"
                value="approve"
                :disabled="selectedIds.length === 0"
                data-testid="bulk-approve-button"
                class="rounded-xl border px-3 py-1.5 text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed transition {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}"
            >
                Duyệt đã chọn (Approve)
            </button>
            <button
                type="submit"
                name="action"
                value="archive"
                data-testid="bulk-archive"
                @click="if (!window.confirm('Lưu trữ ' + selectedIds.length + ' test case? Test case đã lưu trữ không thể chạy lại và không thể hoàn tác.')) $event.preventDefault()"
                :disabled="selectedIds.length === 0"
                class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition"
            >
                Lưu trữ đã chọn (Archive)
            </button>
        </div>
    </form>

    <!-- Test Cases Table -->
    @if ($testCases->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-800/30 px-6 py-16 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-700 bg-slate-800 text-slate-400">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            @if ($filtersActive)
                {{-- Cases hidden by the filter: point at the filter, not at generating data (S14-06). --}}
                <p data-testid="test-cases-empty-filtered" class="text-sm font-semibold text-slate-200">Không có test case nào khớp bộ lọc</p>
                <p class="mt-1 text-xs text-slate-400">
                    Hãy đổi điều kiện lọc hoặc
                    <a href="{{ route('suites.show', $suite) }}" class="font-semibold text-slate-200 underline underline-offset-2 hover:text-white">xóa lọc</a>.
                </p>
            @else
                <p data-testid="test-cases-empty" class="text-sm font-semibold text-slate-200">Chưa có test case nào</p>
                <p class="mt-1 text-xs text-slate-400">Hãy nhấn "Sinh test mới" để tự động sinh test cases cho bộ kiểm thử này.</p>
            @endif
        </div>
    @else
        <div class="rounded-2xl border border-slate-800 bg-slate-800/30 overflow-hidden shadow-xl">
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table data-testid="test-cases-table" class="w-full min-w-[56rem] text-left text-xs text-slate-300">
                    <thead class="bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="w-10 px-4 py-3 text-center">#</th>
                            <th class="min-w-[14rem] px-4 py-3">Tiêu đề & dấu vân tay (fingerprint)</th>
                            <th class="px-4 py-3">Endpoint</th>
                            <th class="px-4 py-3">Nguồn</th>
                            <th class="px-4 py-3">Kỹ thuật</th>
                            <th class="whitespace-nowrap px-4 py-3">Trạng thái</th>
                            <th class="whitespace-nowrap px-4 py-3 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-normal">
                        @foreach ($testCases as $tc)
                            @php
                                $methodColor = match ($tc->endpoint->method->value ?? 'GET') {
                                    'GET' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                    'POST' => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
                                    'PUT' => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    'PATCH' => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
                                    'DELETE' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                    default => 'bg-slate-800 text-slate-300 border-slate-700',
                                };

                                $statusBadge = match ($tc->status->value) {
                                    'VALID' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
                                    'APPROVED' => 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30',
                                    'INVALID' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
                                    'DUPLICATE' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
                                    'ARCHIVED' => 'bg-slate-800 text-slate-400 border-slate-700',
                                    default => 'bg-slate-800 text-slate-300 border-slate-700',
                                };

                                $sourceBadge = match ($tc->source->value) {
                                    'RULE' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30',
                                    'LLM' => 'bg-purple-500/10 text-purple-300 border-purple-500/30',
                                    'HYBRID' => 'bg-pink-500/10 text-pink-300 border-pink-500/30',
                                    'MANUAL' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
                                    default => 'bg-slate-800 text-slate-300 border-slate-700',
                                };
                            @endphp
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="px-4 py-3 text-center">
                                    <input
                                        type="checkbox"
                                        class="tc-checkbox rounded border-slate-700 bg-slate-900 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} h-4 w-4 {{ \App\Support\AccentClasses::checkbox(auth()->user()) }}"
                                        value="{{ $tc->id }}"
                                        aria-label="Chọn test case #{{ $tc->id }}"
                                        x-model="selectedIds"
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('test-cases.show', $tc) }}" class="font-medium text-white transition block {{ \App\Support\RoleLayout::accent(auth()->user()) === 'teal' ? 'hover:text-teal-300' : 'hover:text-indigo-300' }}">
                                        {{ $tc->title }}
                                    </a>
                                    @if (filled($tc->fingerprint))
                                        <span class="font-mono text-[10px] text-slate-400 truncate block max-w-xs mt-0.5" title="{{ $tc->fingerprint }}">
                                            {{ substr($tc->fingerprint, 0, 16) }}...
                                        </span>
                                    @else
                                        <span data-testid="tc-row-fingerprint-empty" class="text-[10px] text-slate-400 block mt-0.5">Chưa có dấu vân tay (fingerprint)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $methodColor }}">
                                            {{ $tc->endpoint->method->value ?? 'REQ' }}
                                        </span>
                                        <span class="font-mono text-xs text-slate-300 truncate max-w-xs" title="{{ $tc->endpoint->path ?? '' }}">
                                            {{ $tc->endpoint->path ?? 'N/A' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded border px-2 py-0.5 text-[10px] font-semibold {{ $sourceBadge }}">
                                        {{ $tc->source->value }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-[11px] text-slate-300" title="{{ \App\Support\EnumTooltip::for($tc->technique->label(), $tc->technique) }}">
                                    {{ $tc->technique->label() }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="whitespace-nowrap rounded border px-2 py-0.5 text-[10px] font-bold tracking-wider {{ $statusBadge }}" title="{{ \App\Support\EnumTooltip::for($tc->status->label(), $tc->status) }}">
                                        {{ $tc->status->label() }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a
                                        href="{{ route('test-cases.show', $tc) }}"
                                        data-testid="test-case-open-link"
                                        class="rounded-lg border px-3 py-1 text-[11px] font-semibold transition inline-block {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}"
                                    >
                                        Xem/Sửa
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($testCases->hasPages())
                <div class="border-t border-slate-800 bg-slate-900/50 px-4 py-3">
                    {{ $testCases->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
