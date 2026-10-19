<?php

namespace Tests\Unit\Services;

use App\Actions\Generation\ImportRecordedLlmJobAction;
use App\Data\Ai\AiGeneratedTestCase;
use App\Enums\ApiSpecFormat;
use App\Services\Dsl\DslValidator;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\JsonPathSubset;
use App\Services\OpenApi\OpenApiParser;
use App\Services\TestEngine\TestFingerprintService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S14-06 regressions from the live QA of the production site (validator, JSONPath, parser
 * messages, recorded showcase messages).
 */
class QaGenerationSpecsS1406Test extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $assertions
     * @param  list<array<string, mixed>>  $extract
     * @return array<string, mixed>
     */
    private function dsl(array $assertions = [], array $extract = []): array
    {
        $step = [
            'name' => 'Get item',
            'request' => ['method' => 'GET', 'path' => '/items'],
            'assertions' => [['type' => 'status', 'equals' => 200], ...$assertions],
        ];
        if ($extract !== []) {
            $step['extract'] = $extract;
        }

        return [
            'dsl_version' => '1.0',
            'title' => 'Get items',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [$step],
        ];
    }

    // -1: validation must not change the caller's DSL (meta-schema defaults) nor its fingerprint.
    public function test_validation_does_not_change_an_object_dsl_with_inline_schema(): void
    {
        $json = (string) json_encode($this->dsl([[
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
        ]]));
        /** @var \stdClass $dsl */
        $dsl = json_decode($json, false);
        $fingerprints = new TestFingerprintService;
        $before = $fingerprints->calculate(json_decode($json, false));

        $result = (new DslValidator)->validate($dsl);

        $this->assertTrue($result->isValid, implode('; ', $result->errors));
        $this->assertSame($json, json_encode($dsl));
        $this->assertSame($before, $fingerprints->calculate($dsl));
        // Validating again (e.g. a second edit) gives the same result and fingerprint.
        $this->assertTrue((new DslValidator)->validate($dsl)->isValid);
        $this->assertSame($before, $fingerprints->calculate($dsl));
    }

    public function test_inline_schema_meta_errors_are_vietnamese(): void
    {
        $result = (new DslValidator)->validate($this->dsl([[
            'type' => 'json_schema',
            'schema' => ['type' => 'object', 'properties' => []],
        ]]));

        $this->assertFalse($result->isValid);
        $message = implode("\n", $result->errors);
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ: $.properties: sai kiểu dữ liệu: cần đối tượng, nhận mảng', $message);
        $this->assertStringNotContainsString('must match', $message);
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedPaths(): array
    {
        return [
            'wildcard' => ['$[*].id'],
            'recursive descent' => ['$..id'],
            'filter' => ['$[?(@.a)]'],
            'slice' => ['$.items[0:2]'],
            'member wildcard' => ['$.*'],
            'trailing dot' => ['$.a.'],
        ];
    }

    // -5: a JSONPath the engine cannot evaluate makes the case INVALID instead of a VALID case that FAILs.
    #[DataProvider('unsupportedPaths')]
    public function test_unsupported_json_path_assertion_is_invalid(string $path): void
    {
        $result = (new DslValidator)->validate($this->dsl([['type' => 'json_path', 'path' => $path, 'op' => 'exists']]));

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('assertion #2: JSONPath '.json_encode($path, JSON_UNESCAPED_SLASHES).' không được hỗ trợ', implode("\n", $result->errors));
    }

    #[DataProvider('unsupportedPaths')]
    public function test_unsupported_extract_path_is_invalid(string $path): void
    {
        $result = (new DslValidator)->validate($this->dsl([], [['var' => 'x', 'from' => 'body', 'path' => $path]]));

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('extract #1: JSONPath', implode("\n", $result->errors));
    }

    /** @return array<string, array{0: string}> */
    public static function supportedPaths(): array
    {
        return [
            'root' => ['$'],
            'members' => ['$.a.b'],
            'index' => ['$[0].id'],
            'quoted key' => ["\$['x-y']"],
            'double quoted key' => ['$["a b"].c'],
        ];
    }

    // Every path the validator accepts resolves in the Assertion Engine without the "not supported" error.
    #[DataProvider('supportedPaths')]
    public function test_supported_json_path_is_valid_and_evaluable(string $path): void
    {
        $this->assertTrue((new DslValidator)->validate($this->dsl([['type' => 'json_path', 'path' => $path, 'op' => 'exists']]))->isValid);
        $this->assertTrue(JsonPathSubset::isSupported($path));

        $data = json_decode('{"a":{"b":1},"x-y":2,"a b":{"c":3}}', false);
        [, , $error] = (new AssertionEngine)->resolveJsonPath($data, $path);
        $this->assertNull($error);
        [, , $listError] = (new AssertionEngine)->resolveJsonPath(json_decode('[{"id":1}]', false), $path);
        $this->assertNull($listError);
    }

    public function test_assertion_engine_reports_the_unsupported_path_as_before(): void
    {
        $engine = new AssertionEngine;

        $this->assertSame([true, 1, null], $engine->resolveJsonPath(json_decode('[{"id":1}]', false), '$[0].id'));
        [$found, , $error] = $engine->resolveJsonPath(json_decode('[{"id":1}]', false), '$[*].id');
        $this->assertFalse($found);
        $this->assertSame('JSONPath không hợp lệ hoặc không được hỗ trợ: $[*].id', $error);
    }

    public function test_json_path_with_a_runtime_variable_is_not_checked(): void
    {
        $dsl = $this->dsl([['type' => 'json_path', 'path' => '$.{{key}}', 'op' => 'exists']]);
        $dsl['variables'] = ['key' => 'name'];

        $this->assertTrue((new DslValidator)->validate($dsl)->isValid);
    }

    // -9: unresolvable $ref shows the reference, no library English or class name.
    public function test_unresolvable_reference_message_is_vietnamese(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/OpenApi/unresolvable-ref.json');
        $result = (new OpenApiParser)->parse($json, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Không giải quyết được tham chiếu $ref "#/components/schemas/NonExistentSchema"', $result->errors[0]);
        foreach (['cebe', 'Failed to', 'Array has no member', 'Object'] as $leak) {
            $this->assertStringNotContainsString($leak, $result->errors[0]);
        }
    }

    // -10b: a YAML custom tag is reported as such, never instantiated.
    public function test_yaml_custom_tag_is_reported_as_unsupported_tag(): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  title: !!python/object/apply:os.system [\"id\"]\n  version: '1'\npaths: {}\n";
        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('dòng 3', $result->errors[0]);
        $this->assertStringContainsString('thẻ tùy biến', $result->errors[0]);
        $this->assertStringNotContainsString('thụt lề', $result->errors[0]);

        $local = (new OpenApiParser)->parse("openapi: 3.0.3\ninfo:\n  title: !custom x\n", ApiSpecFormat::Yaml);
        $this->assertStringContainsString('thẻ tùy biến', $local->errors[0]);
    }

    // -7(4): recorded showcase errors get today's Vietnamese wording.
    public function test_recorded_showcase_errors_are_shown_in_vietnamese(): void
    {
        $cases = ImportRecordedLlmJobAction::withCurrentMessages([
            new AiGeneratedTestCase(['title' => 'x'], 'INVALID', [
                'Repair must preserve the original target endpoint',
                'Repair answer unusable: Answer cut off at max_tokens=1056 (finish_reason=length): the case is larger than the REPAIR output budget (2 x AI_LLM_OUTPUT_TOKENS_PER_CASE + 256)',
                'The properties must match schema: steps',
                'All array items must match schema',
                "Lỗi schema tại 'steps.0': 'assertions' is a required property",
                "Bước 1 ('x'): Tham số truy vấn 'q' không tồn tại.",
            ], 2),
        ]);

        $this->assertSame([
            'Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.',
            'Câu trả lời sửa lỗi của LLM bị cắt do vượt giới hạn token đầu ra: test case quá lớn để sửa tự động.',
            "Bước 1 ('x'): Tham số truy vấn 'q' không tồn tại.",
        ], $cases[0]->validationErrors);
        $this->assertSame(2, $cases[0]->repairAttempts);
        $this->assertSame('INVALID', $cases[0]->status);
    }

    public function test_no_recorded_showcase_case_keeps_an_english_error(): void
    {
        $raw = gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/database/demo/benchmark-v1.0.json.gz'));
        $this->assertIsString($raw);
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);

        $checked = 0;
        foreach ($fixture['benchmarks'] as $benchmark) {
            foreach ($benchmark['recorded_jobs'] as $job) {
                $cases = array_values(array_map(
                    static fn (array $case): AiGeneratedTestCase => AiGeneratedTestCase::fromArray($case),
                    $job['response']['test_cases'],
                ));
                foreach (ImportRecordedLlmJobAction::withCurrentMessages($cases) as $case) {
                    foreach ($case->validationErrors as $message) {
                        $checked++;
                        $this->assertDoesNotMatchRegularExpression('/must match|Repair answer|Answer cut off|Repair must|is a required property|Additional properties/', (string) $message);
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
