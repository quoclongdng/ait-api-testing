{{--
    Self-contained HTML export of a test run (S8-01).
    No external resources and no scripts. Every value from the target or from an LLM is printed
    with escaped output only (docs/DESIGN.md §9); never use the unescaped echo syntax in this file.
--}}
@php
    $run = $report->run;
    $summary = $report->summary;
    $formatMs = fn (?int $ms): string => $ms === null ? '—' : ($ms >= 1000 ? number_format($ms / 1000, 1, ',', '.') . ' giây' : $ms . ' ms');
    $verdictClass = fn (string $v): string => 'v-' . strtolower($v);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'">
    <title>Báo cáo lần chạy #{{ $run->id }} — AI Testing Platform</title>
    <style>
        body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; background: #f8fafc; font-size: 14px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 28px 0 10px; }
        .muted { color: #64748b; font-size: 13px; }
        .cards { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; min-width: 120px; }
        .card b { display: block; font-size: 22px; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 600; font-size: 13px; }
        td.num { text-align: right; }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; word-break: break-all; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
        .v-pass { background: #dcfce7; color: #166534; }
        .v-fail { background: #ffe4e6; color: #9f1239; }
        .v-error { background: #fef3c7; color: #92400e; }
        .v-skipped { background: #e2e8f0; color: #334155; }
        .failure { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 12px; }
        .error-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 10px; margin: 8px 0; }
        footer { margin-top: 32px; }
        /* Wide tables scroll inside their own box on a phone instead of widening the page (S14-06). */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
        .table-wrap > table { min-width: 36rem; }
        .break { word-break: break-word; overflow-wrap: anywhere; }
        @media (max-width: 600px) {
            body { padding: 12px; }
            th, td { padding: 6px; }
            .card { min-width: 0; flex: 1 1 40%; }
        }
    </style>
</head>
<body>
    <h1>Báo cáo lần chạy #{{ $run->id }}</h1>
    <p class="muted">
        Dự án: {{ $run->testSuite->project->name }} · Bộ kiểm thử: {{ $run->testSuite->name }}<br>
        Mục tiêu: <span class="mono">{{ $run->target_base_url }}</span>
        @if ($run->benchmark_variant) · Biến thể: {{ \App\Support\VariantLabel::display($run->benchmark_variant) }} @endif<br>
        Trạng thái: {{ $run->status->label() }} · Ngày chạy: <x-datetime :value="$run->started_at ?? $run->created_at" />
        · Thời lượng: {{ $summary->totalsOnly && $summary->wallDurationMs === null ? 'không có số liệu' : $formatMs($summary->wallDurationMs) }} · Người chạy: {{ $run->triggerer->name }}
    </p>
    @if ($summary->totalsOnly)
        <p class="muted" data-testid="run-totals-only">{{ \App\Data\Reports\RunReportSummary::TOTALS_ONLY_NOTE }}</p>
    @endif

    <div class="cards">
        <div class="card">Tổng số ca<b>{{ $summary->total }}</b></div>
        <div class="card">Tỉ lệ đạt<b>{{ $summary->passRate === null ? '—' : number_format($summary->passRate, 1, ',', '.') . '%' }}</b></div>
        <div class="card">Đạt (PASS)<b>{{ $summary->pass }}</b></div>
        <div class="card">Không đạt (FAIL)<b>{{ $summary->fail }}</b></div>
        <div class="card">Lỗi (ERROR)<b>{{ $summary->error }}</b></div>
        <div class="card">Bỏ qua (SKIPPED)<b>{{ $summary->skipped }}</b></div>
        <div class="card">Thời gian trung bình<b>{{ $formatMs($summary->averageDurationMs) }}</b></div>
    </div>

    <h2>Phân bố thời gian phản hồi</h2>
    @if ($summary->totalsOnly)
    <p class="muted" data-testid="run-timing-unavailable">{{ \App\Data\Reports\RunReportSummary::TOTALS_ONLY_TIMING_NOTE }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>&lt; 50 ms</th><th>50 – 200 ms</th><th>&gt; 200 ms</th></tr>
        <tr>
            <td>{{ $summary->responseTimeBuckets['lt_50'] }}</td>
            <td>{{ $summary->responseTimeBuckets['between_50_200'] }}</td>
            <td>{{ $summary->responseTimeBuckets['gt_200'] }}</td>
        </tr>
    </table></div>
    @endif

    <h2>Thống kê theo từng endpoint</h2>
    <div class="table-wrap"><table>
        <tr>
            <th>Endpoint</th><th>Tổng số ca</th><th>Đạt</th><th>Không đạt</th><th>Lỗi</th><th>Bỏ qua</th><th>Mã trạng thái nhận được</th>
        </tr>
        @forelse ($report->endpoints as $endpoint)
            <tr>
                <td class="mono">{{ $endpoint->method }} {{ $endpoint->path }}</td>
                <td class="num">{{ $endpoint->total }}</td>
                <td class="num">{{ $endpoint->pass }}</td>
                <td class="num">{{ $endpoint->fail }}</td>
                <td class="num">{{ $endpoint->error }}</td>
                <td class="num">{{ $endpoint->skipped }}</td>
                <td class="mono">{{ $endpoint->statusCodes === [] ? '—' : implode(', ', $endpoint->statusCodes) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">{{ $summary->totalsOnly ? 'Không có số liệu theo endpoint: lần chạy này chỉ lưu số liệu tổng.' : 'Lần chạy này chưa có kết quả nào.' }}</td></tr>
        @endforelse
    </table></div>

    <h2>Ca kiểm thử không đạt ({{ $report->failureCount() }})</h2>
    @forelse ($report->failures as $failure)
        <div class="failure">
            <span class="badge {{ $verdictClass($failure->verdict) }}">{{ $failure->verdict }}</span>
            <strong class="break">#{{ $failure->testCaseId }} {{ $failure->title }}</strong>
            <div class="muted mono">{{ $failure->method }} {{ $failure->path }} · {{ $failure->durationMs }} ms</div>
            <div class="mono">Yêu cầu: {{ $failure->requestMethod ?? '—' }} {{ $failure->requestUrl ?? '' }} · Mã trạng thái nhận được: {{ $failure->responseStatus ?? 'không có' }}</div>
            @if ($failure->errorMessage)
                <div class="error-box break">{{ $failure->errorMessage }}</div>
            @endif
            @if ($failure->failedAssertions !== [])
                <div class="table-wrap"><table>
                    <tr><th>Kiểm tra (assertion)</th><th>Kỳ vọng</th><th>Thực tế</th><th>Thông báo</th></tr>
                    @foreach ($failure->failedAssertions as $assertion)
                        <tr>
                            <td class="mono">{{ $assertion['type'] }}</td>
                            <td class="mono">{{ $assertion['expected'] }}</td>
                            <td class="mono">{{ $assertion['actual'] }}</td>
                            <td class="break">{{ $assertion['message'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
        </div>
    @empty
        <p>{{ $summary->totalsOnly ? 'Không có chi tiết từng ca: lần chạy này chỉ lưu số liệu tổng.' : 'Không có ca kiểm thử nào thất bại hoặc lỗi.' }}</p>
    @endforelse

    <h2>Toàn bộ kết quả</h2>
    <div class="table-wrap"><table>
        <tr><th>ID</th><th>Test case</th><th>Endpoint</th><th>Kết quả</th><th>Thời gian</th><th>Mã trạng thái</th></tr>
        @forelse ($report->results as $result)
            <tr>
                <td class="num">{{ $result->id }}</td>
                <td class="break">#{{ $result->test_case_id }} {{ $result->testCase->title }}</td>
                <td class="mono">{{ $result->testCase->endpoint->method->value }} {{ $result->testCase->endpoint->path }}</td>
                <td><span class="badge {{ $verdictClass($result->verdict->value) }}">{{ $result->verdict->value }}</span></td>
                <td class="num">{{ $result->duration_ms }} ms</td>
                <td class="mono">{{ \App\Services\Reports\RunReportBuilder::responseStatus($result) ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" data-testid="run-results-empty">{{ $summary->totalsOnly ? 'Không có kết quả từng ca: lần chạy này chỉ lưu số liệu tổng.' : 'Lần chạy này chưa có kết quả nào.' }}</td></tr>
        @endforelse
    </table></div>

    <footer class="muted">
        Xuất lúc <x-datetime :value="$generatedAt" format="d/m/Y H:i:s" /> bởi AI Testing Platform. Kết luận PASS/FAIL do Assertion Engine quyết định.
    </footer>
</body>
</html>
