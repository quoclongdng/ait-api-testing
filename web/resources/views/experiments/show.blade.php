@extends('layouts.app')

@section('title', 'Thực nghiệm #'.$experiment->id)

@php
    $metrics = $experiment->metrics ?? [];
    $repetitionRows = is_array($metrics['repetitions'] ?? null) ? $metrics['repetitions'] : [];
    $detections = $experiment->bugDetections->groupBy('benchmark_bug_id');
    $repetitionCount = (int) ($experiment->config['repetitions'] ?? 0);
    $isActive = in_array($experiment->status->value, ['PLANNED', 'RUNNING'], true);
@endphp

@section('content')
<div class="space-y-6" @if ($isActive) x-data x-init="setTimeout(() => window.location.reload(), 10000)" @endif>
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <a href="{{ route('experiments.index') }}" class="text-xs {{ \App\Support\AccentClasses::link(auth()->user()) }}">← Danh sách thực nghiệm</a>
            {{-- The status badge is a sibling of the h1, so the heading's accessible name is the title only (S14-06). --}}
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="min-w-0 break-words text-xl font-bold tracking-tight text-white sm:text-2xl">Thực nghiệm #{{ $experiment->id }}: {{ $experiment->name }}</h1>
                <x-status-badge :status="$experiment->status" data-testid="experiment-status" />
            </div>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Benchmark {{ $experiment->benchmark->name }} ({{ $experiment->benchmark->version }}) · Chiến lược <span class="font-mono text-slate-200">{{ $experiment->strategy->value }}</span>
                · Mô hình {{ $experiment->config['model'] ?? 'mặc định của hệ thống' }} · Seed {{ implode(', ', array_map('strval', (array) ($experiment->config['seeds'] ?? [$experiment->config['seed'] ?? '—']))) }}
                · Nhiệt độ {{ \App\Support\LlmDisplay::temperature($experiment->config['temperature'] ?? null) }} · {{ ($experiment->config['reset_per_case'] ?? false) === true ? 'Reset sandbox trước từng case' : 'Reset sandbox trước mỗi lượt chạy' }} · {{ count($experiment->repetitions_data ?? []) }}/{{ $repetitionCount }} lần lặp
            </p>
        </div>
        <div class="flex gap-2 text-xs">
            <a href="{{ route('api.v1.experiments.export', ['experiment' => $experiment->id, 'format' => 'json']) }}" data-testid="experiment-export-json" class="rounded-lg border border-slate-700 px-3 py-2 font-semibold text-slate-200 {{ \App\Support\AccentClasses::hoverBorder(auth()->user()) }}">Xuất JSON</a>
            <a href="{{ route('api.v1.experiments.export', ['experiment' => $experiment->id, 'format' => 'csv']) }}" data-testid="experiment-export-csv" class="rounded-lg border border-slate-700 px-3 py-2 font-semibold text-slate-200 {{ \App\Support\AccentClasses::hoverBorder(auth()->user()) }}">Xuất CSV</a>
        </div>
    </div>

    @if (! empty($metrics['error']))
        <div class="rounded-xl border border-rose-500/40 bg-rose-950/60 p-4 text-sm text-rose-200">
            <p class="font-semibold">Thực nghiệm thất bại</p>
            <p class="mt-1 text-xs text-rose-300">{{ $metrics['error'] }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl lg:col-span-2">
            <div class="border-b border-slate-800 px-5 py-4">
                <h2 class="text-sm font-bold text-white">CHỈ SỐ ĐÁNH GIÁ (TRUNG BÌNH ± ĐỘ LỆCH CHUẨN)</h2>
            </div>
            <table class="w-full text-left text-xs">
                <tbody class="divide-y divide-slate-800/60 text-slate-300">
                    @foreach ($rows as $key => [$label, $format])
                        <tr>
                            <td class="px-5 py-2.5 font-medium text-slate-200">{{ $label }}</td>
                            <td class="px-5 py-2.5 text-right">
                                <x-metric-value :mean="isset($metrics[$key]) && is_numeric($metrics[$key]) ? $metrics[$key] : null" :stddev="$metrics['stddev'][$key] ?? null" :format="$format" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <aside class="space-y-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-xs">
                <h2 class="font-bold uppercase tracking-wider text-slate-300">Tổng quan bug</h2>
                <p class="mt-3 text-slate-400">Số bug cài sẵn: <span class="font-mono text-slate-100">{{ $metrics['bugs_total'] ?? $experiment->benchmark->bugs->count() }}</span></p>
                <p class="mt-1 text-slate-400">Phát hiện trung bình mỗi lần lặp: <span data-testid="bugs-detected-mean" class="font-mono text-slate-100">{{ isset($metrics['bugs_detected_mean']) && is_numeric($metrics['bugs_detected_mean']) ? number_format((float) $metrics['bugs_detected_mean'], 2, ',', '.') : '—' }}</span></p>
                <p class="mt-3 text-[11px] leading-5 text-slate-400">Một bug được tính là phát hiện khi có ≥ 1 test PASS trên bản sạch và FAIL/ERROR trên bản có bug.</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-xs">
                <h2 class="font-bold uppercase tracking-wider text-slate-300">Thời gian</h2>
                <p class="mt-3 text-slate-400">Bắt đầu: <span class="text-slate-200"><x-datetime :value="$experiment->started_at" format="d/m/Y H:i:s" /></span></p>
                <p class="mt-1 text-slate-400">Kết thúc: <span class="text-slate-200"><x-datetime :value="$experiment->finished_at" format="d/m/Y H:i:s" /></span></p>
            </div>
        </aside>
    </div>

    <section class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
        <div class="border-b border-slate-800 px-5 py-4">
            <h2 class="text-sm font-bold text-white">CHI TIẾT TỪNG LẦN LẶP</h2>
        </div>
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Lần</th>
                        <th class="px-4 py-3">Seed</th>
                        <th class="px-4 py-3">Sinh / Hợp lệ / Trùng</th>
                        <th class="px-4 py-3">Bản sạch: Đạt / Không đạt / Lỗi</th>
                        <th class="px-4 py-3">Tỉ lệ hợp lệ (Validity)</th>
                        <th class="px-4 py-3">Tỉ lệ thực thi được (Executable)</th>
                        <th class="px-4 py-3">Tỉ lệ dương tính giả (FPR)</th>
                        <th class="px-4 py-3">Tỉ lệ phát hiện bug (BDR)</th>
                        <th class="px-4 py-3">Độ phủ endpoint</th>
                        <th class="px-4 py-3">Độ phủ mã trạng thái</th>
                        <th class="px-4 py-3">Bộ kiểm thử / Lần chạy sạch</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
                    @forelse ($experiment->repetitions_data ?? [] as $index => $repetition)
                        @php
                            $row = $repetitionRows[$index] ?? null;
                            $repetitionNumber = (int) ($repetition['repetition'] ?? $index + 1);
                            // A 0/0 ratio is "not applicable", not 0%: show — (same as the aggregate view).
                            $denominators = $row ? [
                                'validity_rate' => (int) ($row['generated'] ?? 0),
                                'executable_rate' => (int) ($row['valid'] ?? 0),
                                'false_positive_rate' => (int) ($row['clean_pass'] ?? 0) + (int) ($row['clean_fail'] ?? 0),
                            ] : [];
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5">{{ $repetition['repetition'] ?? $index + 1 }}</td>
                            <td class="px-4 py-2.5">{{ $repetition['seed'] ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                {{ $row ? $row['generated'].' / '.$row['valid'].' / '.$row['duplicate'] : '—' }}
                                @if (isset($generationFailures[$repetitionNumber]))
                                    <span class="mt-1 block font-sans text-[11px] text-rose-300">Sinh test thất bại: {{ $generationFailures[$repetitionNumber] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">{{ $row ? $row['clean_pass'].' / '.$row['clean_fail'].' / '.$row['clean_error'] : '—' }}</td>
                            @foreach (['validity_rate', 'executable_rate', 'false_positive_rate', 'bug_detection_rate', 'endpoint_coverage', 'status_code_coverage'] as $key)
                                <td class="px-4 py-2.5"><x-metric-value :mean="($denominators[$key] ?? 1) > 0 ? ($row[$key] ?? null) : null" /></td>
                            @endforeach
                            <td class="px-4 py-2.5 text-slate-400">#{{ $repetition['test_suite_id'] ?? '—' }} / #{{ $repetition['clean_test_run_id'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-5 py-8 text-center font-sans text-slate-400">Chưa có lần lặp nào hoàn tất.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
        <div class="border-b border-slate-800 px-5 py-4">
            <h2 class="text-sm font-bold text-white">MA TRẬN PHÁT HIỆN BUG</h2>
        </div>
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Mã bug</th>
                        <th class="px-4 py-3">Endpoint</th>
                        <th class="px-4 py-3">Phân loại</th>
                        @for ($r = 1; $r <= max(1, $repetitionCount); $r++)
                            <th class="px-4 py-3">Lần {{ $r }}</th>
                        @endfor
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-300">
                    @foreach ($experiment->benchmark->bugs as $bug)
                        @php $byRepetition = ($detections[$bug->id] ?? collect())->keyBy('repetition'); @endphp
                        <tr>
                            <td data-testid="matrix-bug-code" class="whitespace-nowrap px-4 py-2 font-mono text-cyan-300">{{ $bug->code }}</td>
                            <td class="px-4 py-2 font-mono">{{ $bug->method->value }} {{ $bug->path }}</td>
                            <td class="px-4 py-2 text-slate-400" title="{{ \App\Support\EnumTooltip::for($bug->category->label(), $bug->category) }}">{{ $bug->category->label() }}</td>
                            @for ($r = 1; $r <= max(1, $repetitionCount); $r++)
                                @php $detection = $byRepetition[$r] ?? null; @endphp
                                <td class="whitespace-nowrap px-4 py-2 font-mono">
                                    @if ($detection === null)
                                        <span class="text-slate-400">—</span>
                                    @elseif ($detection->detected)
                                        <span class="text-emerald-400" title="Test case phát hiện: {{ implode(', ', array_map('strval', $detection->detecting_test_case_ids ?? [])) }}" aria-hidden="true">✓</span><span class="sr-only">Phát hiện</span>
                                    @else
                                        <span class="text-rose-400" title="Không phát hiện" aria-hidden="true">✗</span><span class="sr-only">Không phát hiện</span>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
