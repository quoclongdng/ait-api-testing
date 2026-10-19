@extends('layouts.app')

@section('title', 'Báo cáo lần chạy #' . $run->id)

@php
    $summary = $report->summary;
    $formatMs = fn (?int $ms): string => $ms === null ? '—' : ($ms >= 1000 ? number_format($ms / 1000, 1, ',', '.') . ' giây' : $ms . ' ms');
    $statusLabels = [
        'QUEUED' => 'Đang chờ',
        'RUNNING' => 'Đang chạy',
        'COMPLETED' => 'Hoàn thành',
        'FAILED' => 'Thất bại',
        'CANCELLED' => 'Đã hủy',
    ];
    $verdictRows = [
        ['label' => 'PASS', 'count' => $summary->pass, 'bar' => 'bg-emerald-500'],
        ['label' => 'FAIL', 'count' => $summary->fail, 'bar' => 'bg-rose-500'],
        ['label' => 'ERROR', 'count' => $summary->error, 'bar' => 'bg-amber-500'],
        ['label' => 'SKIPPED', 'count' => $summary->skipped, 'bar' => 'bg-slate-500'],
    ];
    $executed = max(1, $summary->executed());
    $bucketRows = [
        ['label' => '< 50 ms', 'count' => $summary->responseTimeBuckets['lt_50']],
        ['label' => '50 – 200 ms', 'count' => $summary->responseTimeBuckets['between_50_200']],
        ['label' => '> 200 ms', 'count' => $summary->responseTimeBuckets['gt_200']],
    ];
    $runDate = $run->started_at ?? $run->created_at;
@endphp

@section('content')
<div class="space-y-6">

    <!-- Header & Breadcrumbs (M13 Header) -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $project) }}" class="hover:text-white transition">{{ $project->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.show', $suite) }}" class="hover:text-white transition">{{ $suite->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.runs.index', $suite) }}" class="hover:text-white transition">Lần chạy</a>
                <span>/</span>
                <a id="report-run-link" href="{{ route('runs.show', $run) }}" class="hover:text-white transition">Lần chạy #{{ $run->id }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">Báo cáo</span>
            </div>
            <h1 class="flex flex-wrap items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Báo cáo lần chạy #{{ $run->id }}</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm break-all">
                Bộ kiểm thử: <span class="text-slate-200">{{ $suite->name }}</span>
                · Mục tiêu: <span class="font-mono text-cyan-400">{{ $run->target_base_url }}</span>
                @if ($run->benchmark_variant)
                    · Biến thể: <span class="font-mono text-slate-200">{{ \App\Support\VariantLabel::display($run->benchmark_variant) }}</span>
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Trạng thái: <span class="font-semibold text-slate-200">{{ $statusLabels[$run->status->value] ?? $run->status->value }}</span>
                · Ngày chạy: <span class="text-slate-200"><x-datetime :value="$runDate" /></span>
                · Thời lượng: <span class="text-slate-200">{{ $summary->totalsOnly && $summary->wallDurationMs === null ? 'không có số liệu' : $formatMs($summary->wallDurationMs) }}</span>
                · Người chạy: <span class="text-slate-200">{{ $run->triggerer->name }}</span>
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Xuất báo cáo</span>
            <div class="flex flex-wrap items-center gap-2">
                <a id="btn-export-json" href="{{ route('api.v1.runs.export', ['run' => $run, 'format' => 'json']) }}"
                   class="rounded-xl border border-slate-700 bg-slate-800/60 px-3.5 py-2 text-xs font-semibold text-slate-200 transition {{ \App\Support\AccentClasses::hoverBorder(auth()->user()) }} hover:text-white">
                    Xuất file JSON
                </a>
                <a id="btn-export-csv" href="{{ route('api.v1.runs.export', ['run' => $run, 'format' => 'csv']) }}"
                   class="rounded-xl border border-slate-700 bg-slate-800/60 px-3.5 py-2 text-xs font-semibold text-slate-200 transition {{ \App\Support\AccentClasses::hoverBorder(auth()->user()) }} hover:text-white">
                    Xuất bảng CSV
                </a>
                <a id="btn-export-html" href="{{ route('api.v1.runs.export', ['run' => $run, 'format' => 'html']) }}"
                   class="rounded-xl px-3.5 py-2 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}">
                    Xuất báo cáo HTML
                </a>
            </div>
        </div>
    </div>

    @if (in_array($run->status->value, ['QUEUED', 'RUNNING'], true))
        <div class="rounded-2xl border border-amber-500/30 bg-amber-950/20 px-4 py-3 text-xs text-amber-200">
            Lần chạy chưa kết thúc. Báo cáo chỉ phản ánh các kết quả đã ghi nhận đến thời điểm hiện tại.
        </div>
    @endif

    @if ($summary->totalsOnly)
        <div class="rounded-2xl border border-sky-500/30 bg-sky-950/20 px-4 py-3 text-xs text-sky-200" data-testid="run-totals-only">
            {{ \App\Data\Reports\RunReportSummary::TOTALS_ONLY_NOTE }}
        </div>
    @endif

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
            <span class="text-xs font-medium text-slate-400">Tổng số ca</span>
            <p class="mt-1 text-2xl font-bold text-white">{{ $summary->total }}</p>
        </div>
        <div data-testid="report-stat-pass-rate" class="rounded-2xl border p-4 {{ \App\Support\AccentClasses::statCard(auth()->user()) }}">
            <span class="text-xs font-medium {{ \App\Support\AccentClasses::statCardLabel(auth()->user()) }}">Tỉ lệ đạt</span>
            <p class="mt-1 text-2xl font-bold {{ \App\Support\AccentClasses::statCardValue(auth()->user()) }}">{{ $summary->passRate === null ? '—' : number_format($summary->passRate, 1, ',', '.') . '%' }}</p>
        </div>
        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-4">
            <span class="text-xs font-medium text-emerald-400">Đạt (PASS)</span>
            <p class="mt-1 text-2xl font-bold text-emerald-300">{{ $summary->pass }}</p>
        </div>
        <div class="rounded-2xl border border-rose-500/30 bg-rose-950/20 p-4">
            <span class="text-xs font-medium text-rose-400">Không đạt (FAIL)</span>
            <p class="mt-1 text-2xl font-bold text-rose-300">{{ $summary->fail }}</p>
        </div>
        <div class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4">
            <span class="text-xs font-medium text-amber-400">Lỗi (ERROR)</span>
            <p class="mt-1 text-2xl font-bold text-amber-300">{{ $summary->error }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700/60 bg-slate-900/40 p-4">
            <span class="text-xs font-medium text-slate-400">Bỏ qua (SKIPPED)</span>
            <p class="mt-1 text-2xl font-bold text-slate-300">{{ $summary->skipped }}</p>
        </div>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5">
            <h2 class="text-sm font-semibold text-white">Phân bố kết quả (verdict)</h2>
            <div class="mt-4 space-y-3">
                @foreach ($verdictRows as $row)
                    @php $pct = $summary->percentOfTotal($row['count']); @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs">
                            <x-verdict-badge :verdict="$row['label']" />
                            <span class="text-slate-300">{{ number_format($pct, 1, ',', '.') }}% ({{ $row['count'] }})</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-900">
                            <div class="h-2 rounded-full {{ $row['bar'] }}" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5">
            <h2 class="text-sm font-semibold text-white">Phân bố thời gian phản hồi</h2>
            @if ($summary->totalsOnly)
                <p class="mt-4 text-xs text-slate-400" data-testid="run-timing-unavailable">{{ \App\Data\Reports\RunReportSummary::TOTALS_ONLY_TIMING_NOTE }}</p>
            @else
            <p class="mt-1 text-xs text-slate-400">
                Tổng thời gian thực thi: {{ $formatMs($summary->totalDurationMs) }} · Trung bình mỗi ca: {{ $formatMs($summary->averageDurationMs) }}
            </p>
            <div class="mt-4 space-y-3">
                @foreach ($bucketRows as $row)
                    @php $pct = $summary->executed() === 0 ? 0 : round($row['count'] * 100 / $executed, 1); @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-300">
                            <span class="font-mono">{{ $row['label'] }}</span>
                            <span>{{ $row['count'] }} ca</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-900">
                            <div class="h-2 rounded-full bg-cyan-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    <!-- Per-endpoint breakdown -->
    <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-800/40">
        <div class="border-b border-slate-800 px-5 py-4">
            <h2 class="text-sm font-semibold text-white">Thống kê theo từng endpoint</h2>
        </div>
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-slate-900/60 text-slate-400">
                    <tr>
                        <th class="px-5 py-3 font-medium">Endpoint</th>
                        <th class="px-5 py-3 font-medium text-right">Tổng số ca</th>
                        <th class="px-5 py-3 font-medium text-right">Đạt</th>
                        <th class="px-5 py-3 font-medium text-right">Không đạt</th>
                        <th class="px-5 py-3 font-medium text-right">Lỗi</th>
                        <th class="px-5 py-3 font-medium text-right">Bỏ qua</th>
                        <th class="px-5 py-3 font-medium">Mã trạng thái nhận được</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @forelse ($report->endpoints as $endpoint)
                        <tr class="hover:bg-slate-800/40">
                            <td class="px-5 py-3">
                                <span data-testid="report-endpoint-method" class="mr-2 font-bold {{ \App\Support\AccentClasses::label(auth()->user()) }}">{{ $endpoint->method }}</span>
                                <span class="font-mono text-slate-200">{{ $endpoint->path }}</span>
                            </td>
                            <td class="px-5 py-3 text-right text-white">{{ $endpoint->total }}</td>
                            <td class="px-5 py-3 text-right text-emerald-300">{{ $endpoint->pass }}</td>
                            <td class="px-5 py-3 text-right text-rose-300">{{ $endpoint->fail }}</td>
                            <td class="px-5 py-3 text-right text-amber-300">{{ $endpoint->error }}</td>
                            <td class="px-5 py-3 text-right text-slate-400">{{ $endpoint->skipped }}</td>
                            <td class="px-5 py-3 font-mono text-slate-300">{{ $endpoint->statusCodes === [] ? '—' : implode(', ', $endpoint->statusCodes) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-slate-400">{{ $summary->totalsOnly ? 'Không có số liệu theo endpoint: lần chạy này chỉ lưu số liệu tổng.' : 'Lần chạy này chưa có kết quả nào.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Failed cases with assertion details -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/40">
        <div class="border-b border-slate-800 px-5 py-4">
            <h2 class="text-sm font-semibold text-white">Ca kiểm thử không đạt ({{ $report->failureCount() }})</h2>
            <p class="mt-1 text-xs text-slate-400">Kết luận PASS/FAIL do bộ kiểm tra assertion tất định quyết định, không do LLM.{{ $summary->totalsOnly ? ' Số ca là FAIL + ERROR của số liệu tổng.' : ' Bấm vào từng ca để xem chi tiết assertion.' }}</p>
        </div>

        <div class="divide-y divide-slate-800">
            @forelse ($report->failures as $failure)
                <div x-data="{ open: false }" class="px-5 py-3">
                    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="failure-detail-{{ $failure->testCaseId }}" class="flex w-full items-center gap-3 text-left">
                        <x-verdict-badge :verdict="$failure->verdict" />
                        <span class="font-mono text-xs text-slate-400">#{{ $failure->testCaseId }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-100">{{ $failure->title }}</span>
                        <span class="hidden font-mono text-xs text-slate-400 sm:inline">{{ $failure->method }} {{ $failure->path }}</span>
                        <span class="text-xs text-slate-400">{{ $failure->durationMs }} ms</span>
                        <svg class="h-4 w-4 text-slate-400 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div id="failure-detail-{{ $failure->testCaseId }}" x-show="open" x-cloak class="mt-3 space-y-3 rounded-xl border border-slate-800 bg-slate-900/60 p-4 text-xs">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="break-all">
                                <span class="text-slate-400">Yêu cầu:</span>
                                <span class="font-mono text-slate-200">{{ $failure->requestMethod ?? '—' }} {{ $failure->requestUrl ?? '' }}</span>
                            </div>
                            <div>
                                <span class="text-slate-400">Mã trạng thái nhận được:</span>
                                <span class="font-mono text-slate-200">{{ $failure->responseStatus ?? 'Không có response' }}</span>
                            </div>
                        </div>

                        @if ($failure->errorMessage)
                            <div class="rounded-lg border border-amber-500/30 bg-amber-950/20 px-3 py-2 text-amber-200 break-words">
                                {{ $failure->errorMessage }}
                            </div>
                        @endif

                        @if ($failure->failedAssertions !== [])
                            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                                <table class="min-w-full text-left">
                                    <thead class="text-slate-400">
                                        <tr>
                                            <th class="py-1.5 pr-4 font-medium">Kiểm tra (assertion)</th>
                                            <th class="py-1.5 pr-4 font-medium">Kỳ vọng</th>
                                            <th class="py-1.5 pr-4 font-medium">Thực tế</th>
                                            <th class="py-1.5 font-medium">Thông báo</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800">
                                        @foreach ($failure->failedAssertions as $assertion)
                                            <tr>
                                                <td class="py-1.5 pr-4 font-mono text-rose-300">{{ $assertion['type'] }}</td>
                                                <td class="py-1.5 pr-4 font-mono text-slate-200 break-all">{{ \Illuminate\Support\Str::limit($assertion['expected'], 300) }}</td>
                                                <td class="py-1.5 pr-4 font-mono text-slate-200 break-all">{{ \Illuminate\Support\Str::limit($assertion['actual'], 300) }}</td>
                                                <td class="py-1.5 text-slate-300 break-words">{{ $assertion['message'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-slate-400">Không có assertion thất bại được ghi nhận.</p>
                        @endif

                        <a href="{{ route('test-cases.show', $failure->testCaseId) }}" class="inline-block {{ \App\Support\AccentClasses::link(auth()->user()) }}">Mở chi tiết test case →</a>
                    </div>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-slate-400">{{ $summary->totalsOnly ? 'Không có chi tiết từng ca: lần chạy này chỉ lưu số liệu tổng.' : 'Không có ca kiểm thử nào thất bại hoặc lỗi.' }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
