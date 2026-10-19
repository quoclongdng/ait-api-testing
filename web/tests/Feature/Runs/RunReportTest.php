<?php

namespace Tests\Feature\Runs;

use App\Enums\HttpMethod;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Reports\RunReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * S8-01: M13 run report screen and GET /api/v1/runs/{id}/export.
 */
class RunReportTest extends TestCase
{
    use RefreshDatabase;

    private const XSS_TITLE = '<script>alert("xss")</script> Tạo đơn hàng';

    private const FORMULA_TITLE = '=HYPERLINK("http://evil.test","bấm vào")';

    private User $owner;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create(['name' => 'Người kiểm thử A']);
        $project = Project::factory()->for($this->owner, 'owner')->create(['name' => 'Dự án báo cáo']);
        $suite = TestSuite::factory()->for($project)->for($this->owner, 'creator')->create([
            'name' => 'Bộ kiểm thử đơn hàng',
        ]);

        $orders = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Post,
            'path' => '/orders',
        ]);
        $orderById = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Get,
            'path' => '/orders/{id}',
        ]);

        $this->run = TestRun::factory()->for($suite)->for($this->owner, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
            'target_base_url' => 'http://localhost:8080',
            'started_at' => Carbon::parse('2026-10-05 10:00:00'),
            'finished_at' => Carbon::parse('2026-10-05 10:00:06'),
            'totals' => ['total' => 4, 'pass' => 1, 'fail' => 2, 'error' => 1, 'skipped' => 0],
        ]);

        $this->addResult($orders, 'Tạo đơn hàng hợp lệ nhận 201', TestVerdict::Pass, 42, 201, [
            ['type' => 'status', 'passed' => true, 'expected' => 201, 'actual' => 201, 'message' => null],
        ]);
        $this->addResult($orders, self::XSS_TITLE, TestVerdict::Fail, 120, 201, [
            ['type' => 'status', 'passed' => false, 'expected' => 422, 'actual' => 201, 'message' => '<img src=x onerror=alert(1)>'],
        ]);
        $this->addResult($orderById, self::FORMULA_TITLE, TestVerdict::Fail, 35, 200, [
            ['type' => 'json_path', 'passed' => false, 'expected' => ['status' => 'PAID'], 'actual' => '-1+2', 'message' => 'Giá trị không khớp'],
        ]);
        $this->addResult($orderById, 'Lấy đơn hàng quá thời gian chờ', TestVerdict::Error, 5002, null, [], '@SUM(1+1) timeout');
    }

    public function test_guest_is_redirected_and_api_export_requires_auth(): void
    {
        $this->get("/runs/{$this->run->id}/report")->assertRedirect('/login');

        $this->getJson("/api/v1/runs/{$this->run->id}/export?format=json")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_owner_sees_report_screen_with_summary_endpoints_and_failures(): void
    {
        $response = $this->actingAs($this->owner)->get("/runs/{$this->run->id}/report");

        $response->assertOk()
            ->assertSee('Báo cáo lần chạy #'.$this->run->id)
            ->assertSee('Bộ kiểm thử đơn hàng')
            ->assertSee('Tỉ lệ đạt')
            ->assertSee('25,0%')
            ->assertSee('6,0 giây')
            ->assertSee('Thống kê theo từng endpoint')
            ->assertSee('/orders/{id}')
            ->assertSee('Ca kiểm thử không đạt (3)')
            ->assertSee('Xuất bảng CSV')
            ->assertSee('Giá trị không khớp')
            ->assertSee(route('api.v1.runs.export', ['run' => $this->run, 'format' => 'html']), false);

        // Untrusted data is escaped on the screen.
        $response->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_failure_detail_labels_are_vietnamese_on_screen_and_html_export(): void
    {
        // Regression (S13-03 QA): the failure detail read "Request:" and "Assertion" next to "Kỳ vọng"/"Thực tế".
        $screen = (string) $this->actingAs($this->owner)->get("/runs/{$this->run->id}/report")->assertOk()->getContent();
        $export = (string) $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=html")->assertOk()->getContent();

        foreach (['screen' => $screen, 'html export' => $export] as $where => $html) {
            $this->assertStringContainsString('Yêu cầu:', $html, $where);
            $this->assertStringContainsString('Kiểm tra (assertion)</th>', $html, $where);
            $this->assertStringNotContainsString('Request:', $html, $where);
            $this->assertStringNotContainsString('>Assertion<', $html, $where);
        }
        $this->assertStringNotContainsString('Response:', $export);
        $this->assertStringContainsString('Mã trạng thái nhận được:', $export);
    }

    public function test_admin_can_view_any_report(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/runs/{$this->run->id}/report")
            ->assertOk()
            ->assertSee('Báo cáo lần chạy #');

        $this->actingAs($admin)->get("/api/v1/runs/{$this->run->id}/export?format=csv")->assertOk();
    }

    public function test_suite_screen_links_recent_runs_to_report_for_owner_and_admin(): void
    {
        $reportUrl = route('runs.report', $this->run);
        $suiteId = $this->run->test_suite_id;

        $this->actingAs($this->owner)->get("/suites/{$suiteId}")
            ->assertOk()
            ->assertSee('Lần chạy gần đây')
            ->assertSee('Xem báo cáo')
            ->assertSee('1 Đạt')
            ->assertSee($reportUrl, false);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get("/suites/{$suiteId}")
            ->assertOk()
            ->assertSee($reportUrl, false);

        $other = User::factory()->tester()->create();
        $this->actingAs($other)->get("/suites/{$suiteId}")->assertForbidden();
    }

    public function test_suite_screen_shows_empty_state_without_runs(): void
    {
        $suite = TestSuite::factory()->for($this->owner, 'creator')->create([
            'project_id' => $this->run->testSuite->project_id,
        ]);

        $this->actingAs($this->owner)->get("/suites/{$suite->id}")
            ->assertOk()
            ->assertSee('Bộ kiểm thử này chưa có lần chạy nào.');
    }

    public function test_other_tester_is_forbidden_on_screen_and_export(): void
    {
        $other = User::factory()->tester()->create();

        $this->actingAs($other)->get("/runs/{$this->run->id}/report")->assertForbidden();

        foreach (['json', 'csv', 'html'] as $format) {
            $this->actingAs($other)->getJson("/api/v1/runs/{$this->run->id}/export?format={$format}")
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'FORBIDDEN');
        }
    }

    public function test_export_validates_format_and_missing_run(): void
    {
        $this->actingAs($this->owner)->getJson("/api/v1/runs/{$this->run->id}/export?format=pdf")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['format']], 'trace_id']);

        $this->actingAs($this->owner)->getJson("/api/v1/runs/{$this->run->id}/export")
            ->assertStatus(422);

        $this->actingAs($this->owner)->getJson('/api/v1/runs/999999/export?format=json')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_json_export_parses_and_matches_report_contract(): void
    {
        $response = $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=json");

        $response->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="test-run-'.$this->run->id.'-report.json"', (string) $response->headers->get('Content-Disposition'));

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertSame(['run', 'summary', 'results'], array_keys($data));
        $this->assertSame($this->run->id, $data['run']['id']);
        $this->assertSame('COMPLETED', $data['run']['status']);
        $this->assertSame(4, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['pass']);
        $this->assertSame(2, $data['summary']['fail']);
        $this->assertSame(1, $data['summary']['error']);
        $this->assertEquals(25.0, $data['summary']['pass_rate']);
        $this->assertSame(6000, $data['summary']['wall_duration_ms']);
        $this->assertSame(['lt_50' => 2, 'between_50_200' => 1, 'gt_200' => 1], $data['summary']['response_time_buckets']);
        $this->assertCount(2, $data['summary']['endpoints']);
        $this->assertCount(4, $data['results']);
        $this->assertSame(
            ['id', 'test_run_id', 'test_case_id', 'verdict', 'duration_ms', 'request_log', 'response_log', 'assertion_results', 'error_message', 'created_at'],
            array_keys($data['results'][0]),
        );

        $byPath = array_column($data['summary']['endpoints'], null, 'path');
        $this->assertSame(['total' => 2, 'pass' => 1, 'fail' => 1], array_intersect_key($byPath['/orders'], ['total' => 0, 'pass' => 0, 'fail' => 0]));
        $this->assertSame([201], $byPath['/orders']['status_codes']);
        $this->assertSame(1, $byPath['/orders/{id}']['error']);
    }

    public function test_csv_export_has_bom_parses_and_neutralises_formulas(): void
    {
        $response = $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=csv");

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv"', (string) $response->headers->get('Content-Disposition'));

        $content = (string) $response->getContent();
        $this->assertStringStartsWith(RunReportExporter::UTF8_BOM, $content);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));

        $rows = $this->parseCsv(substr($content, strlen(RunReportExporter::UTF8_BOM)));
        $this->assertCount(5, $rows);
        $this->assertSame('ID kết quả', $rows[0][0]);
        $this->assertSame('Tiêu đề', $rows[0][2]);
        foreach ($rows as $row) {
            $this->assertCount(11, $row);
        }

        $titles = array_column(array_slice($rows, 1), 2);
        $this->assertContains(self::XSS_TITLE, $titles);
        $this->assertContains("'".self::FORMULA_TITLE, $titles);

        // No cell may start with a formula trigger.
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell !== '') {
                    $this->assertNotContains($cell[0], ['=', '+', '-', '@', "\t", "\r"], "Unsafe CSV cell: {$cell}");
                }
            }
        }

        $errorRow = collect($rows)->first(fn (array $r): bool => $r[5] === 'ERROR');
        $this->assertIsArray($errorRow);
        $this->assertSame("'@SUM(1+1) timeout", $errorRow[10]);

        $failRow = collect($rows)->first(fn (array $r): bool => $r[2] === self::XSS_TITLE);
        $this->assertIsArray($failRow);
        $this->assertStringContainsString('status: kỳ vọng 422, thực tế 201', $failRow[9]);
    }

    public function test_html_export_is_self_contained_and_escapes_untrusted_data(): void
    {
        $response = $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=html");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.html"', (string) $response->headers->get('Content-Disposition'));

        $html = (string) $response->getContent();
        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        $this->assertStringContainsString('<h1>Báo cáo lần chạy #'.$this->run->id.'</h1>', $html);
        $this->assertStringContainsString('Ca kiểm thử không đạt (3)', $html);
        $this->assertStringContainsString('Giá trị không khớp', $html);

        // Escaped untrusted content, no active content, no external resources.
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertDoesNotMatchRegularExpression('/<(link|iframe)\b|\bsrc\s*=\s*["\']?https?:/i', $html);

        // The document parses as HTML.
        $dom = new \DOMDocument;
        $this->assertTrue(@$dom->loadHTML($html));
        $this->assertSame(0, $dom->getElementsByTagName('script')->length);
    }

    public function test_report_of_run_without_results_renders(): void
    {
        $suite = $this->run->testSuite;
        $empty = TestRun::factory()->for($suite)->for($this->owner, 'triggerer')->create([
            'status' => TestRunStatus::Queued,
        ]);

        $this->actingAs($this->owner)->get("/runs/{$empty->id}/report")
            ->assertOk()
            ->assertSee('Lần chạy này chưa có kết quả nào.')
            ->assertSee('Lần chạy chưa kết thúc.');

        $data = $this->actingAs($this->owner)->get("/api/v1/runs/{$empty->id}/export?format=json")->json();
        $this->assertSame(0, $data['summary']['total']);
        $this->assertNull($data['summary']['pass_rate']);

        $csv = (string) $this->actingAs($this->owner)->get("/api/v1/runs/{$empty->id}/export?format=csv")->getContent();
        $this->assertCount(1, $this->parseCsv(substr($csv, 3)));
    }

    public function test_sanitize_csv_cell(): void
    {
        $this->assertSame("'=1+1", RunReportExporter::sanitizeCsvCell('=1+1'));
        $this->assertSame("'+1", RunReportExporter::sanitizeCsvCell('+1'));
        $this->assertSame("'-1", RunReportExporter::sanitizeCsvCell('-1'));
        $this->assertSame("'@cmd", RunReportExporter::sanitizeCsvCell('@cmd'));
        $this->assertSame("'\tx", RunReportExporter::sanitizeCsvCell("\tx"));
        $this->assertSame("'\rx", RunReportExporter::sanitizeCsvCell("\rx"));
        $this->assertSame('Bình thường', RunReportExporter::sanitizeCsvCell('Bình thường'));
        $this->assertSame('', RunReportExporter::sanitizeCsvCell(''));
    }

    /**
     * @param  list<array<string, mixed>>  $assertions
     */
    private function addResult(Endpoint $endpoint, string $title, TestVerdict $verdict, int $durationMs, ?int $status, array $assertions, ?string $error = null): void
    {
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $this->run->test_suite_id,
            'endpoint_id' => $endpoint->id,
            'title' => $title,
        ]);

        TestResult::factory()->create([
            'test_run_id' => $this->run->id,
            'test_case_id' => $case->id,
            'verdict' => $verdict,
            'duration_ms' => $durationMs,
            'request_log' => ['method' => $endpoint->method->value, 'url' => 'http://localhost:8080'.$endpoint->path, 'headers' => [], 'body' => null],
            'response_log' => $status === null ? ['steps' => []] : ['status' => $status, 'headers' => [], 'body' => '{}'],
            'assertion_results' => $assertions,
            'error_message' => $error,
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
