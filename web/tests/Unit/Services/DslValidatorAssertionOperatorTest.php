<?php

namespace Tests\Unit\Services;

use App\Services\Dsl\DslValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression (S10-01): a `matches` pattern that does not compile or an unknown json_path `type`
 * name used to pass validation and then always FAIL at run time instead of being INVALID (D04).
 */
class DslValidatorAssertionOperatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $assertion
     * @return array<string, mixed>
     */
    private function dsl(array $assertion): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => 'Operator values',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'variables' => ['pattern' => '^a'],
            'steps' => [[
                'name' => 'List',
                'request' => ['method' => 'GET', 'path' => '/items'],
                'assertions' => [['type' => 'status', 'equals' => 200], $assertion],
            ]],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function brokenOracles(): array
    {
        return [
            'json_path regex' => [['type' => 'json_path', 'path' => '$.name', 'op' => 'matches', 'value' => '[a-z(']],
            'header regex' => [['type' => 'header', 'name' => 'X-Trace', 'op' => 'matches', 'value' => '(unclosed']],
            'unknown type name' => [['type' => 'json_path', 'path' => '$.name', 'op' => 'type', 'value' => 'strng']],
            'non-string type name' => [['type' => 'json_path', 'path' => '$.name', 'op' => 'type', 'value' => 1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    #[DataProvider('brokenOracles')]
    public function test_broken_operator_values_are_invalid(array $assertion): void
    {
        $result = (new DslValidator)->validate($this->dsl($assertion));

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('assertion #2', implode("\n", $result->errors));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function sound(): array
    {
        return [
            'regex' => [['type' => 'json_path', 'path' => '$.name', 'op' => 'matches', 'value' => '^[0-9]+$']],
            'regex with tilde' => [['type' => 'header', 'name' => 'X-Trace', 'op' => 'matches', 'value' => '~v[0-9]']],
            'type name' => [['type' => 'json_path', 'path' => '$.data', 'op' => 'type', 'value' => 'array']],
            'type alias' => [['type' => 'json_path', 'path' => '$.data', 'op' => 'type', 'value' => 'Integer']],
            'runtime pattern' => [['type' => 'json_path', 'path' => '$.name', 'op' => 'matches', 'value' => '{{pattern}}']],
        ];
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    #[DataProvider('sound')]
    public function test_sound_operator_values_stay_valid(array $assertion): void
    {
        $result = (new DslValidator)->validate($this->dsl($assertion));

        $this->assertTrue($result->isValid, implode("\n", $result->errors));
    }
}
