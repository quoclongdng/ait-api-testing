<?php

namespace Tests\Unit\Services;

use App\Services\Dsl\DslValidator;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\JsonPathSubset;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review of S14-06 (JSONPath subset): the DSL validator, the Assertion Engine and the executor's
 * extract must agree on one grammar. The executor had its own copy, which rejected a quoted key
 * with an invalid escape ($["bad\q"]) that the shared parser accepted, so such an extract was
 * VALID but ERRORed at run time.
 */
class QaGenerationSpecsS1406ReviewTest extends TestCase
{
    private function executor(): TestExecutor
    {
        return new TestExecutor(
            new FakeTargetHttpClient,
            new TargetAllowlistValidator(['benchmark'], new FakeTargetHostResolver(['benchmark' => ['127.0.0.1']])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dslWithExtract(string $path): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => 'Extract',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [[
                'name' => 'Get items',
                'request' => ['method' => 'GET', 'path' => '/items'],
                'extract' => [['var' => 'id', 'from' => 'body', 'path' => $path]],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEscapes(): array
    {
        return [
            'double quoted' => ['$["bad\\q"]'],
            'single quoted' => ["\$['bad\\q']"],
            'lone surrogate' => ['$["\\ud800"]'],
        ];
    }

    #[DataProvider('invalidEscapes')]
    public function test_quoted_key_with_an_invalid_escape_is_rejected_everywhere(string $path): void
    {
        $this->assertNull(JsonPathSubset::parse($path));

        $result = (new DslValidator)->validate($this->dslWithExtract($path));
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('không được hỗ trợ', implode(' ', $result->errors));

        [$found, , $error] = (new AssertionEngine)->resolveJsonPath((object) ['bad\\q' => 1], $path);
        $this->assertFalse($found);
        $this->assertStringContainsString('không được hỗ trợ', (string) $error);

        $this->expectException(\InvalidArgumentException::class);
        $this->executor()->extractJsonPath((object) ['bad\\q' => 1], $path);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function supportedExtracts(): array
    {
        return [
            'member' => ['$.id', 7],
            'nested index' => ['$.items[1].name', 'b'],
            'quoted key' => ["\$['x-y']", 'dash'],
            'escaped quote' => ['$["a\\"b"]', 'quote'],
            'unicode escape' => ['$["\\u0069d"]', 7],
            'no dollar' => ['items[0].name', 'a'],
        ];
    }

    #[DataProvider('supportedExtracts')]
    public function test_every_path_the_validator_accepts_is_extracted_by_the_executor(string $path, mixed $expected): void
    {
        $data = json_decode('{"id":7,"x-y":"dash","a\"b":"quote","items":[{"name":"a"},{"name":"b"}]}', false);

        $this->assertTrue((new DslValidator)->validate($this->dslWithExtract($path))->isValid, $path);
        $this->assertSame($expected, $this->executor()->extractJsonPath($data, $path));
        $this->assertSame([true, $expected, null], (new AssertionEngine)->resolveJsonPath($data, $path));
    }
}
