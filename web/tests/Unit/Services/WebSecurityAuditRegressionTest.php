<?php

namespace Tests\Unit\Services;

use App\Data\Execution\StepExecutionResult;
use App\Enums\ApiSpecFormat;
use App\Services\OpenApi\OpenApiParser;
use App\Services\Reports\RunReportExporter;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the S10-02 web-security audit (round 1): parser
 * expansion bombs, credential header masking and CSV formula neutralisation.
 */
class WebSecurityAuditRegressionTest extends TestCase
{
    private const HEADER_YAML = "openapi: 3.0.3\ninfo:\n  title: Bomb\n  version: '1'\npaths: {}\n";

    public function test_yaml_alias_bomb_is_rejected_without_exhausting_memory(): void
    {
        $yaml = self::HEADER_YAML.'x-a0: &a0 ['.implode(',', array_fill(0, 10, 'x'))."]\n";
        for ($i = 1; $i <= 7; $i++) {
            $prev = '*a'.($i - 1);
            $yaml .= "x-a{$i}: &a{$i} [".implode(',', array_fill(0, 10, $prev))."]\n";
        }
        $this->assertLessThan(1024, strlen($yaml));

        $before = memory_get_usage();
        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('mở rộng alias YAML', $result->errors[0]);
        $this->assertLessThan(64 * 1024 * 1024, memory_get_usage() - $before);
    }

    public function test_internal_ref_fan_out_chain_is_rejected(): void
    {
        $result = (new OpenApiParser)->parse($this->fanOutSpec(6), ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('tham chiếu $ref', $result->errors[0]);
    }

    public function test_small_ref_fan_out_is_still_accepted(): void
    {
        $result = (new OpenApiParser)->parse($this->fanOutSpec(2), ApiSpecFormat::Json);

        $this->assertTrue($result->isValid, implode("\n", $result->errors));
    }

    public function test_very_long_ref_chain_is_rejected_instead_of_crashing(): void
    {
        $schemas = ['s0' => ['type' => 'string']];
        for ($i = 1; $i <= 600; $i++) {
            $schemas["s{$i}"] = ['$ref' => '#/components/schemas/s'.($i - 1)];
        }
        $spec = $this->specWithSchemas($schemas, 's600');

        $result = (new OpenApiParser)->parse($spec, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
    }

    public function test_recursive_ref_is_still_accepted(): void
    {
        $schemas = [
            'Node' => [
                'type' => 'object',
                'properties' => [
                    'left' => ['$ref' => '#/components/schemas/Node'],
                    'right' => ['$ref' => '#/components/schemas/Node'],
                ],
            ],
        ];

        $result = (new OpenApiParser)->parse($this->specWithSchemas($schemas, 'Node'), ApiSpecFormat::Json);

        $this->assertTrue($result->isValid, implode("\n", $result->errors));
    }

    public function test_credential_headers_are_masked_in_logs(): void
    {
        $masked = StepExecutionResult::maskHeaders([
            'Authorization' => 'Bearer a',
            'Proxy-Authorization' => 'Basic b',
            'Cookie' => 'sid=c',
            'Set-Cookie' => ['sid=d; HttpOnly'],
            'X-API-Key' => 'e',
            'x-auth-token' => 'f',
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame('***', $masked['Authorization']);
        $this->assertSame('***', $masked['Proxy-Authorization']);
        $this->assertSame('***', $masked['Cookie']);
        $this->assertSame(['***'], $masked['Set-Cookie']);
        $this->assertSame('***', $masked['X-API-Key']);
        $this->assertSame('***', $masked['x-auth-token']);
        $this->assertSame('application/json', $masked['Content-Type']);
    }

    public function test_csv_formula_after_leading_whitespace_or_newline_is_neutralised(): void
    {
        $this->assertSame("' =HYPERLINK(\"x\")", RunReportExporter::sanitizeCsvCell(' =HYPERLINK("x")'));
        $this->assertSame("'\n=cmd", RunReportExporter::sanitizeCsvCell("\n=cmd"));
        $this->assertSame("'  \t@SUM(A1)", RunReportExporter::sanitizeCsvCell("  \t@SUM(A1)"));
        $this->assertSame(' plain text', RunReportExporter::sanitizeCsvCell(' plain text'));
        $this->assertSame('', RunReportExporter::sanitizeCsvCell(''));
    }

    private function fanOutSpec(int $levels): string
    {
        $schemas = ['s0' => ['type' => 'string']];
        for ($n = 1; $n <= $levels; $n++) {
            $properties = [];
            for ($p = 0; $p < 10; $p++) {
                $properties["p{$p}"] = ['$ref' => '#/components/schemas/s'.($n - 1)];
            }
            $schemas["s{$n}"] = ['type' => 'object', 'properties' => $properties];
        }

        return $this->specWithSchemas($schemas, "s{$levels}");
    }

    /**
     * @param  array<string, mixed>  $schemas
     */
    private function specWithSchemas(array $schemas, string $responseSchema): string
    {
        return (string) json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Fan-out', 'version' => '1'],
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/'.$responseSchema],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => ['schemas' => $schemas],
        ]);
    }
}
