<?php

namespace Tests\Unit\Services;

use App\Enums\TestTechnique;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleGenerator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class RuleGeneratorOverflowTest extends TestCase
{
    public function test_signed_int64_midpoints_and_nested_unique_arrays_do_not_overflow(): void
    {
        foreach ([
            [-9000000000000000000, 9000000000000000000, 0],
            [PHP_INT_MIN, PHP_INT_MAX, -1],
            [-2, -1, -2],
            [PHP_INT_MAX - 2, PHP_INT_MAX, PHP_INT_MAX - 1],
            [PHP_INT_MIN, PHP_INT_MIN + 2, PHP_INT_MIN + 1],
        ] as [$min, $max, $midpoint]) {
            $itemSchema = ['type' => 'integer', 'minimum' => $min, 'maximum' => $max];
            $generator = new RuleGenerator;
            $this->assertSame($midpoint, $generator->generateValidValueForSchema($itemSchema, []));
            $this->assertGeneratedHappyPath($itemSchema, $midpoint);
            if ($max - $min >= 2) {
                $arraySchema = ['type' => 'array', 'minItems' => 3, 'uniqueItems' => true, 'items' => $itemSchema];
                $this->assertGeneratedHappyPath($arraySchema, [$midpoint - 1, $midpoint, $midpoint + 1]);
                $this->assertGeneratedHappyPath(
                    ['type' => 'object', 'required' => ['values'], 'properties' => ['values' => $arraySchema]],
                    (object) ['values' => [$midpoint - 1, $midpoint, $midpoint + 1]],
                );
            }
        }
    }

    public function test_unique_integer_candidates_use_available_space_in_both_directions(): void
    {
        $step = 3000000000000000000;
        foreach ([
            [0, 9000000000000000000, [0, $step, 2 * $step, 3 * $step]],
            [-9000000000000000000, 0, [-3 * $step, -2 * $step, -$step, 0]],
            [-9000000000000000000, 9000000000000000000, [-3 * $step, -2 * $step, -$step, 0, $step, 2 * $step, 3 * $step]],
        ] as [$min, $max, $witness]) {
            $itemSchema = ['type' => 'integer', 'minimum' => $min, 'maximum' => $max, 'multipleOf' => $step];
            $this->assertGeneratedHappyPath($itemSchema, 0);
            $schema = ['type' => 'array', 'minItems' => count($witness), 'maxItems' => count($witness), 'uniqueItems' => true, 'items' => $itemSchema];
            $this->assertGeneratedHappyPath($schema, $witness);
        }
    }

    public function test_bcmath_runtime_supports_numeric_generation(): void
    {
        $this->assertTrue(extension_loaded('bcmath'));
        $this->assertGeneratedHappyPath(['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'multipleOf' => 2], 4);
    }

    public function test_integer_multiple_near_int64_boundaries_does_not_overflow(): void
    {
        $generator = new RuleGenerator;

        // Singleton domains near PHP_INT_MAX with multipleOf = 1
        foreach ([PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MAX - 2] as $val) {
            $schema = ['type' => 'integer', 'minimum' => $val, 'maximum' => $val, 'multipleOf' => 1];
            $generated = $generator->generateValidValueForSchema($schema, []);
            $this->assertSame($val, $generated);
            $this->assertGeneratedHappyPath($schema, $val);
        }

        // Singleton domains near PHP_INT_MIN with multipleOf = 1
        foreach ([PHP_INT_MIN, PHP_INT_MIN + 1, PHP_INT_MIN + 2] as $val) {
            $schema = ['type' => 'integer', 'minimum' => $val, 'maximum' => $val, 'multipleOf' => 1];
            $generated = $generator->generateValidValueForSchema($schema, []);
            $this->assertSame($val, $generated);
            $this->assertGeneratedHappyPath($schema, $val);
        }

        // Narrow domain near PHP_INT_MAX with multipleOf = 2
        // PHP_INT_MAX is odd, so PHP_INT_MAX - 1 is the largest even multiple
        $evenMax = PHP_INT_MAX - 1;
        $schemaMaxEven = ['type' => 'integer', 'minimum' => PHP_INT_MAX - 5, 'maximum' => PHP_INT_MAX, 'multipleOf' => 2];
        $this->assertGeneratedHappyPath($schemaMaxEven, $evenMax);

        // Narrow domain near PHP_INT_MIN with multipleOf = 2
        // PHP_INT_MIN is even (-9223372036854775808)
        $schemaMinEven = ['type' => 'integer', 'minimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MIN + 5, 'multipleOf' => 2];
        $this->assertGeneratedHappyPath($schemaMinEven, PHP_INT_MIN);

        // Exclusive bounds near PHP_INT_MAX and PHP_INT_MIN verified directly on the generator
        $this->assertSame(
            PHP_INT_MAX - 1,
            $generator->generateValidValueForSchema(['type' => 'integer', 'minimum' => PHP_INT_MAX - 1, 'exclusiveMaximum' => PHP_INT_MAX, 'multipleOf' => 1], []),
        );
        $this->assertSame(
            PHP_INT_MIN + 1,
            $generator->generateValidValueForSchema(['type' => 'integer', 'exclusiveMinimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MIN + 1, 'multipleOf' => 1], []),
        );
        $this->assertSame(
            PHP_INT_MAX - 3,
            $generator->generateValidValueForSchema(['type' => 'integer', 'minimum' => PHP_INT_MAX - 5, 'exclusiveMaximum' => PHP_INT_MAX, 'multipleOf' => 1], []),
        );
        $this->assertSame(
            PHP_INT_MIN + 3,
            $generator->generateValidValueForSchema(['type' => 'integer', 'exclusiveMinimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MIN + 5, 'multipleOf' => 1], []),
        );

        // Exclusive bounds where Opis JSON Schema validator float precision allows validation
        $schemaExcMax = ['type' => 'integer', 'minimum' => PHP_INT_MAX - 5000, 'exclusiveMaximum' => PHP_INT_MAX, 'multipleOf' => 1];
        $this->assertGeneratedHappyPath($schemaExcMax, PHP_INT_MAX - 4096);

        $schemaExcMin = ['type' => 'integer', 'exclusiveMinimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MIN + 5000, 'multipleOf' => 1];
        $this->assertGeneratedHappyPath($schemaExcMin, PHP_INT_MIN + 4096);
    }

    public function test_conditional_and_composition_schemas_not_bypassed_by_multiple_of_fallback(): void
    {
        $generator = new RuleGenerator;

        // 1. Direct control 1: if/then without multipleOf
        $schemaCond1 = ['type' => 'integer', 'if' => ['minimum' => 1], 'then' => ['maximum' => 0]];
        $this->assertTrue($generator->isValueConformingToSchema(0, $schemaCond1));
        $this->assertFalse($generator->isValueConformingToSchema(1, $schemaCond1));

        // 2. Direct control 2: if/then with multipleOf = 1
        $schemaCond2 = ['type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'multipleOf' => 1, 'if' => ['minimum' => 1], 'then' => ['maximum' => 0]];
        $this->assertTrue($generator->isValueConformingToSchema(0, $schemaCond2));
        $this->assertFalse($generator->isValueConformingToSchema(2, $schemaCond2));

        // 3. Conditional unique array with items having if/else
        $itemsSchema = ['type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'if' => ['multipleOf' => 2], 'else' => ['maximum' => 0]];
        $this->assertTrue($generator->isValueConformingToSchema(0, $itemsSchema));
        $this->assertFalse($generator->isValueConformingToSchema(1, $itemsSchema));
        $this->assertTrue($generator->isValueConformingToSchema(2, $itemsSchema));
        $this->assertFalse($generator->isValueConformingToSchema(3, $itemsSchema));
        $this->assertTrue($generator->isValueConformingToSchema(4, $itemsSchema));

        $arraySchema = ['type' => 'array', 'minItems' => 3, 'uniqueItems' => true, 'items' => $itemsSchema];
        $genArray = $generator->generateValidValueForSchema($arraySchema, []);
        $this->assertCount(3, $genArray);
        $this->assertCount(3, array_unique($genArray));
        foreach ($genArray as $elem) {
            $this->assertContains($elem, [0, 2, 4]);
        }
        $this->assertGeneratedHappyPath($arraySchema, [0, 2, 4]);

        // 4. Conditional constraint near INT64 boundaries with distance exceeding float precision limit
        $schemaInt64Cond = [
            'type' => 'integer',
            'minimum' => PHP_INT_MAX - 10000,
            'maximum' => PHP_INT_MAX,
            'multipleOf' => 1,
            'if' => ['minimum' => PHP_INT_MAX - 1000],
            'then' => ['maximum' => PHP_INT_MAX - 5000],
        ];
        $this->assertFalse($generator->isValueConformingToSchema(PHP_INT_MAX, $schemaInt64Cond));
        $this->assertTrue($generator->isValueConformingToSchema(PHP_INT_MAX - 6000, $schemaInt64Cond));
    }

    public function test_referenced_conditional_schemas_preserved_in_multiple_of_fallback(): void
    {
        $generator = new RuleGenerator;

        // Components: Even = {type:integer, multipleOf:2}
        $components = ['Even' => ['type' => 'integer', 'multipleOf' => 2]];

        // --- Finding 1: referenced condition must retain multipleOf semantics ---

        // Schema: integer [0..4], multipleOf:1, if:$ref Even, else:maximum:0
        // 1 is NOT even → else:{maximum:0} → 1 > 0 → INVALID
        $schemaRef = [
            'type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'multipleOf' => 1,
            'if' => ['$ref' => '#/components/schemas/Even'],
            'else' => ['maximum' => 0],
        ];
        $this->assertTrue($generator->isValueConformingToSchema(0, $schemaRef, $components), '0 is even → VALID');
        $this->assertFalse($generator->isValueConformingToSchema(1, $schemaRef, $components), '1 is odd → else:{maximum:0} → INVALID');
        $this->assertTrue($generator->isValueConformingToSchema(2, $schemaRef, $components), '2 is even → VALID');
        $this->assertFalse($generator->isValueConformingToSchema(3, $schemaRef, $components), '3 is odd → INVALID');
        $this->assertTrue($generator->isValueConformingToSchema(4, $schemaRef, $components), '4 is even → VALID');

        // Referenced condition WITHOUT root multipleOf (purely conditional, no precision path)
        $schemaRefNoMultiple = [
            'type' => 'integer', 'minimum' => 0, 'maximum' => 4,
            'if' => ['$ref' => '#/components/schemas/Even'],
            'else' => ['maximum' => 0],
        ];
        $this->assertTrue($generator->isValueConformingToSchema(0, $schemaRefNoMultiple, $components));
        $this->assertFalse($generator->isValueConformingToSchema(1, $schemaRefNoMultiple, $components));
        $this->assertTrue($generator->isValueConformingToSchema(2, $schemaRefNoMultiple, $components));

        // Number/float variant: 1.0 must also be rejected
        $schemaRefFloat = [
            'type' => 'number', 'minimum' => 0, 'maximum' => 4, 'multipleOf' => 1,
            'if' => ['$ref' => '#/components/schemas/Even'],
            'else' => ['maximum' => 0],
        ];
        $this->assertFalse($generator->isValueConformingToSchema(1.0, $schemaRefFloat, $components), '1.0: odd → INVALID');
        $this->assertTrue($generator->isValueConformingToSchema(2.0, $schemaRefFloat, $components), '2.0: even → VALID');

        // --- Finding 2: unique integer sampler must propagate componentsSchemas ---

        // Items schema WITHOUT root multipleOf (isolates sampler context from Finding 1)
        $itemsRefNoMultiple = [
            'type' => 'integer', 'minimum' => 0, 'maximum' => 4,
            'if' => ['$ref' => '#/components/schemas/Even'],
            'else' => ['maximum' => 0],
        ];
        $this->assertFalse($generator->isValueConformingToSchema(1, $itemsRefNoMultiple, $components));
        $this->assertFalse($generator->isValueConformingToSchema(3, $itemsRefNoMultiple, $components));
        $this->assertTrue($generator->isValueConformingToSchema(0, $itemsRefNoMultiple, $components));
        $this->assertTrue($generator->isValueConformingToSchema(2, $itemsRefNoMultiple, $components));
        $this->assertTrue($generator->isValueConformingToSchema(4, $itemsRefNoMultiple, $components));

        // Unique array: sampler must thread components to reject candidates 1 and 3
        $arrayRefSchema = [
            'type' => 'array', 'minItems' => 3, 'uniqueItems' => true,
            'items' => $itemsRefNoMultiple,
        ];
        $this->assertGeneratedHappyPathWithComponents($arrayRefSchema, [0, 2, 4], $components);

        // Variant WITH root multipleOf:1 on items (both findings interact)
        $itemsRefWithMultiple = [
            'type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'multipleOf' => 1,
            'if' => ['$ref' => '#/components/schemas/Even'],
            'else' => ['maximum' => 0],
        ];
        $arrayRefMultipleSchema = [
            'type' => 'array', 'minItems' => 3, 'uniqueItems' => true,
            'items' => $itemsRefWithMultiple,
        ];
        $this->assertGeneratedHappyPathWithComponents($arrayRefMultipleSchema, [0, 2, 4], $components);
    }

    /** @param array<string, mixed> $schema */
    private function assertGeneratedHappyPath(array $schema, mixed $witness): void
    {
        $oracle = new Validator;
        $jsonSchema = json_decode(json_encode($schema, JSON_THROW_ON_ERROR));
        $this->assertTrue($oracle->validate($witness, $jsonSchema)->isValid(), 'Witness must satisfy the original schema.');
        $endpoint = [
            'method' => 'POST', 'path' => '/values', 'parameters' => [],
            'request_body' => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Invalid']],
        ];
        $cases = (new RuleGenerator)->generate($endpoint, [TestTechnique::HappyPath]);
        $this->assertCount(1, $cases);
        $body = json_decode(json_encode($cases[0]['steps'][0]['request']['body'], JSON_THROW_ON_ERROR));
        $this->assertTrue($oracle->validate($body, $jsonSchema)->isValid(), 'Generated body must satisfy the original schema.');
        $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
        // Also exercise the public API with all default techniques, not only HAPPY_PATH.
        $allCases = (new RuleGenerator)->generate($endpoint);
        $this->assertNotEmpty($allCases);
        foreach ($allCases as $case) {
            $result = (new DslValidator)->validate($case, ['endpoints' => [$endpoint]]);
            $this->assertTrue($result->isValid, implode('; ', $result->errors));
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     */
    private function assertGeneratedHappyPathWithComponents(array $schema, mixed $witness, array $components): void
    {
        $oracle = new Validator;
        // Build a full OpenAPI-style schema document so $ref resolution works.
        $fullSchema = array_merge($schema, ['components' => ['schemas' => $components]]);
        $jsonSchema = json_decode(json_encode($schema, JSON_THROW_ON_ERROR));
        $jsonFullSchema = json_decode(json_encode($fullSchema, JSON_THROW_ON_ERROR));
        $this->assertTrue($oracle->validate($witness, $jsonFullSchema)->isValid(), 'Witness must satisfy the original schema.');

        $endpoint = [
            'method' => 'POST', 'path' => '/values', 'parameters' => [],
            'request_body' => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Invalid']],
        ];
        $cases = (new RuleGenerator)->generate($endpoint, [TestTechnique::HappyPath], $components);
        $this->assertCount(1, $cases, 'Must generate exactly one HAPPY_PATH case.');
        $body = json_decode(json_encode($cases[0]['steps'][0]['request']['body'], JSON_THROW_ON_ERROR));
        $this->assertTrue($oracle->validate($body, $jsonFullSchema)->isValid(), 'Generated body must satisfy the original schema with components.');

        // Each element of an array body must only be from the valid set {0,2,4}.
        if (is_array($witness) && is_array($body)) {
            foreach ($body as $elem) {
                $this->assertContains($elem, $witness, "Array element $elem must be in the expected valid set.");
            }
        }
    }
}
