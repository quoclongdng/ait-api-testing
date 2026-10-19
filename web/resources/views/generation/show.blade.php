@extends('layouts.app')

@section('title', 'Tiến độ sinh test #' . $job->id)

@section('content')
<div class="space-y-6">

    <!-- Header & Breadcrumbs (M11 Show Header) -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $job->project) }}" class="hover:text-white transition">{{ $job->project->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.show', $suite) }}" class="hover:text-white transition">{{ $suite->name }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">Tiến trình #{{ $job->id }}</span>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Tiến độ sinh test #{{ $job->id }}</h1>
            </div>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Bộ kiểm thử: <span class="text-white font-semibold">{{ $suite->name }}</span> · Thực thi bởi {{ $job->creator->name }} · {{ $job->created_at->diffForHumans() }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <a
                href="{{ route('suites.show', $suite) }}"
                data-testid="btn-view-suite"
                class="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Xem bộ kiểm thử
            </a>

            <a
                href="{{ route('suites.generation-jobs.create', $suite) }}"
                class="rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:text-white transition"
            >
                Sinh tiếp
            </a>

            @if ($job->status->value === 'QUEUED')
                {{-- Hide once polling reports the job left QUEUED (§4.1 only allows cancel from QUEUED). --}}
                <form method="POST" action="{{ route('generation-jobs.cancel', $job) }}"
                      data-testid="cancel-generation-form"
                      x-data="{ status: 'QUEUED' }"
                      @generation-status.window="status = $event.detail"
                      x-show="status === 'QUEUED'">
                    @csrf
                    <button
                        type="submit"
                        data-confirm="Bạn có chắc muốn hủy tiến trình sinh test này?"
                        class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2.5 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 transition"
                    >
                        Hủy tiến trình
                    </button>
                </form>
            @endif
        </div>
    </div>

    <x-generation-progress :job="$job" :summary="$summary" />

    <!-- List of Generated Test Cases -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/30 overflow-hidden shadow-xl space-y-4 p-5">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                DANH SÁCH TEST CASE ĐÃ SINH RA TỪ TIẾN TRÌNH NÀY ({{ $testCases->count() }})
            </h2>
            <a href="{{ route('suites.show', $suite) }}" class="text-xs font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">
                Xem toàn bộ trong bộ kiểm thử →
            </a>
        </div>

        @if ($testCases->isEmpty())
            <p class="text-xs text-slate-400 py-4 text-center">Chưa có test case nào được lưu từ tiến trình này.</p>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table data-testid="generated-cases-table" class="w-full min-w-[44rem] text-left text-xs text-slate-300">
                    <thead class="bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="min-w-[14rem] px-3 py-2.5">Tiêu đề</th>
                            <th class="px-3 py-2.5">Endpoint</th>
                            <th class="px-3 py-2.5">Kỹ thuật</th>
                            <th class="whitespace-nowrap px-3 py-2.5">Trạng thái</th>
                            <th class="whitespace-nowrap px-3 py-2.5 text-right">Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-normal">
                        @foreach ($testCases as $tc)
                            @php
                                $tcMethodColor = match ($tc->endpoint->method->value ?? 'GET') {
                                    'GET' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                    'POST' => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
                                    'PUT' => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    'PATCH' => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
                                    'DELETE' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                    default => 'bg-slate-800 text-slate-300 border-slate-700',
                                };

                                $tcStatusBadge = match ($tc->status->value) {
                                    'VALID' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
                                    'APPROVED' => 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30',
                                    'INVALID' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
                                    'DUPLICATE' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
                                    default => 'bg-slate-800 text-slate-400 border-slate-700',
                                };
                            @endphp
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="min-w-[14rem] px-3 py-2.5 font-medium text-white break-words">
                                    {{ $tc->title }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $tcMethodColor }}">
                                            {{ $tc->endpoint->method->value ?? 'REQ' }}
                                        </span>
                                        <span class="font-mono text-slate-300">
                                            {{ $tc->endpoint->path ?? '' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-[11px] text-slate-400" title="{{ \App\Support\EnumTooltip::for($tc->technique->label(), $tc->technique) }}">
                                    {{ $tc->technique->label() }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="whitespace-nowrap rounded border px-2 py-0.5 text-[10px] font-bold tracking-wider {{ $tcStatusBadge }}" title="{{ \App\Support\EnumTooltip::for($tc->status->label(), $tc->status) }}">
                                        {{ $tc->status->label() }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                    <a
                                        href="{{ route('test-cases.show', $tc) }}"
                                        data-testid="generated-case-link"
                                        class="rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition inline-block {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}"
                                    >
                                        Xem
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
