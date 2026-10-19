<?php

namespace Tests\Unit\Services;

use App\Data\OpenApi\ParsedEndpoint;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Enums\HttpMethod;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleGenerator;
use App\Services\OpenApi\OpenApiParser;
use Illuminate\Database\Eloquent\Collection;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class RuleGeneratorRegressionTest extends TestCase
{
    public function test_large_number_enum_and_const_parameters_preserve_int64_literals(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;

        foreach ([
            'query' => 'value',
            'header' => 'X-Value',
            'path' => 'value',
        ] as $location => $parameterName) {
            foreach ([9007199254740993, -9007199254740993, PHP_INT_MAX, PHP_INT_MIN] as $literal) {
                foreach (['enum' => ['enum' => [$literal]], 'const' => ['const' => $literal]] as $constraint => $valueConstraint) {
                    $path = $location === 'path' ? '/values/{value}' : '/values';
                    $parameter = ['name' => $parameterName, 'in' => $location, 'required' => true, 'schema' => ['type' => 'number'] + $valueConstraint];
                    $operation = ['responses' => ['200' => ['description' => 'OK']]];
                    if ($location === 'path') {
                        $pathItem = ['parameters' => [$parameter], 'get' => $operation];
                    } else {
                        $operation['parameters'] = [$parameter];
                        $pathItem = ['get' => $operation];
                    }

                    $parsed = $parser->parse(json_encode([
                        'openapi' => '3.1.0',
                        'info' => ['title' => 'Int64 parameter regression', 'version' => '1'],
                        'paths' => [$path => $pathItem],
                    ], JSON_THROW_ON_ERROR), ApiSpecFormat::Json);
                    $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));
                    $parsedParameterSchema = $parsed->endpoints[0]->toArray()['parameters'][0]['schema'];
                    $parsedLiteral = $constraint === 'enum' ? $parsedParameterSchema['enum'][0] : $parsedParameterSchema['const'];
                    $this->assertSame($literal, $parsedLiteral);

                    foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
                        $cases = $generator->generateForSpec($spec, [TestTechnique::HappyPath]);
                        $this->assertCount(1, $cases, "{$location} {$constraint} {$literal} should produce a HAPPY_PATH");
                        $request = $cases[0]['steps'][0]['request'];
                        $actual = match ($location) {
                            'query' => $request['query']['value'],
                            'header' => $request['headers']['X-Value'],
                            'path' => $request['path_params']['value'],
                        };
                        $this->assertTrue(
                            $actual === $literal || $actual === (string) $literal,
                            "Generated {$location} value must preserve {$literal} exactly"
                        );
                        $result = (new DslValidator)->validate($cases[0], $parsed);
                        $this->assertTrue($result->isValid, implode('; ', $result->errors));
                    }

                    $request = ['path_params' => [], 'query' => [], 'headers' => [], 'has_body' => false];
                    if ($location === 'path') {
                        $request['path_params']['value'] = (string) $literal;
                    } elseif ($location === 'query') {
                        $request['query']['value'] = (string) $literal;
                    } else {
                        $request['headers']['X-Value'] = (string) $literal;
                    }
                    $this->assertTrue($generator->isRequestConformingToEndpoint($request, $parsed->endpoints[0]->toArray()));
                    $wrongLiteral = $literal === PHP_INT_MIN ? $literal + 1 : $literal - 1;
                    if ($location === 'path') {
                        $request['path_params']['value'] = (string) $wrongLiteral;
                    } elseif ($location === 'query') {
                        $request['query']['value'] = (string) $wrongLiteral;
                    } else {
                        $request['headers']['X-Value'] = (string) $wrongLiteral;
                    }
                    $this->assertFalse($generator->isRequestConformingToEndpoint($request, $parsed->endpoints[0]->toArray()));
                }
            }
        }
    }

    public function test_empty_parameter_and_property_schemas_generate_happy_paths_for_parser_and_model_specs(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;

        foreach (['query', 'header', 'path', 'body-property'] as $location) {
            foreach (['empty' => new \stdClass, 'typed' => ['type' => 'string']] as $schemaCase => $propertySchema) {
                $path = $location === 'path' ? '/values/{value}' : '/values';
                $parameterName = $location === 'header' ? 'X-Value' : 'value';
                $operation = ['responses' => ['201' => ['description' => 'Created']]];
                $pathItem = [];
                if ($location === 'body-property') {
                    $operation['requestBody'] = [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['value'],
                            'properties' => ['value' => $propertySchema],
                        ]]],
                    ];
                    $oracleSchema = $operation['requestBody']['content']['application/json']['schema'];
                } else {
                    $parameter = ['name' => $parameterName, 'in' => $location, 'required' => true, 'schema' => $propertySchema];
                    if ($location === 'path') {
                        $pathItem['parameters'] = [$parameter];
                    } else {
                        $operation['parameters'] = [$parameter];
                    }
                    $oracleSchema = $propertySchema;
                }
                $pathItem['get'] = $operation;

                $parsed = $parser->parse(json_encode([
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'Empty schema regression', 'version' => '1'],
                    'paths' => [$path => $pathItem],
                ], JSON_THROW_ON_ERROR), ApiSpecFormat::Json);
                $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

                $oracleSchemaJson = json_encode($oracleSchema, JSON_THROW_ON_ERROR);
                $oracleWitnessJson = $location === 'body-property' ? '{"value":"test"}' : '"test"';
                $oracle = (new Validator)->validate(
                    json_decode($oracleWitnessJson, false, 512, JSON_THROW_ON_ERROR),
                    json_decode($oracleSchemaJson, false, 512, JSON_THROW_ON_ERROR),
                );
                $this->assertTrue($oracle->isValid(), "Original {$location} {$schemaCase} schema must accept its witness");

                foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
                    try {
                        $cases = $generator->generateForSpec($spec, [TestTechnique::HappyPath]);
                    } catch (\Throwable $exception) {
                        $this->fail("{$location} {$schemaCase} schema aborted generation: ".get_class($exception).': '.$exception->getMessage());
                    }
                    $this->assertCount(1, $cases, "{$location} {$schemaCase} schema should produce a HAPPY_PATH");
                    $result = (new DslValidator)->validate($cases[0], $parsed);
                    $this->assertTrue($result->isValid, implode('; ', $result->errors));
                }
            }
        }
    }

    public function test_number_parameter_enum_and_const_use_json_numeric_equality(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;

        foreach ([
            'query' => ['/values' => ['get' => ['parameters' => [['name' => 'value', 'in' => 'query', 'required' => true]]]]],
            'header' => ['/values' => ['get' => ['parameters' => [['name' => 'X-Value', 'in' => 'header', 'required' => true]]]]],
            'path' => ['/values/{value}' => ['parameters' => [['name' => 'value', 'in' => 'path', 'required' => true]], 'get' => []]],
        ] as $location => $paths) {
            foreach (['enum' => ['enum' => [2]], 'const' => ['const' => 2]] as $constraint => $constraintSchema) {
                $pathsWithSchema = $paths;
                $operationPath = array_key_first($pathsWithSchema);
                if ($location === 'path') {
                    $pathsWithSchema[$operationPath]['parameters'][0]['schema'] = ['type' => 'number'] + $constraintSchema;
                } else {
                    $pathsWithSchema[$operationPath]['get']['parameters'][0]['schema'] = ['type' => 'number'] + $constraintSchema;
                }
                $pathsWithSchema[$operationPath]['get']['responses'] = ['200' => ['description' => 'OK']];

                $parsed = $parser->parse(json_encode([
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'Numeric parameter regression', 'version' => '1'],
                    'paths' => $pathsWithSchema,
                ], JSON_THROW_ON_ERROR), ApiSpecFormat::Json);
                $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

                foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
                    $cases = $generator->generateForSpec($spec, [TestTechnique::HappyPath]);
                    $this->assertCount(1, $cases, "{$location} {$constraint} should produce a HAPPY_PATH");
                    $result = (new DslValidator)->validate($cases[0], $parsed);
                    $this->assertTrue($result->isValid, implode('; ', $result->errors));
                }

                $endpoint = $parsed->endpoints[0]->toArray();
                foreach ([2, 2.0] as $witness) {
                    $request = ['path_params' => [], 'query' => [], 'headers' => [], 'has_body' => false];
                    if ($location === 'path') {
                        $request['path_params']['value'] = $witness;
                    } elseif ($location === 'query') {
                        $request['query']['value'] = $witness;
                    } else {
                        $request['headers']['X-Value'] = $witness;
                    }
                    $this->assertTrue(
                        $generator->isRequestConformingToEndpoint($request, $endpoint),
                        "{$location} {$constraint} should accept JSON number witness ".json_encode($witness)
                    );
                }
                $this->assertFalse($generator->isValueConformingToSchema('2', ['type' => 'number', 'enum' => [2]]));
                $this->assertFalse($generator->isValueConformingToSchema(true, ['type' => 'number', 'const' => 2]));
            }
        }
    }

    public function test_json_numeric_equality_preserves_integer_precision_and_integer_valued_floats(): void
    {
        $generator = new RuleGenerator;

        $this->assertTrue($generator->isValueConformingToSchema(2.0, ['type' => 'integer', 'const' => 2]));
        $this->assertFalse($generator->isValueConformingToSchema(2.5, ['type' => 'integer', 'const' => 2]));
        $this->assertTrue($generator->isValueConformingToSchema(9007199254740992.0, ['type' => 'number', 'const' => 9007199254740992]));
        $this->assertFalse($generator->isValueConformingToSchema(9007199254740992.0, ['type' => 'number', 'const' => 9007199254740993]));
        $this->assertTrue($generator->isValueConformingToSchema([], ['type' => 'array', 'const' => []]));
    }

    public function test_nullable_union_null_enum_and_const_generate_happy_paths_for_parser_and_model_specs(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;

        foreach (['integer', 'number', 'string', 'boolean', 'object', 'array'] as $type) {
            foreach (['enum' => ['enum' => [null]], 'const' => ['const' => null]] as $constraint => $constraintSchema) {
                $document = [
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'Nullable property regression', 'version' => '1'],
                    'paths' => ['/values' => ['post' => [
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['value'],
                                'properties' => ['value' => ['type' => [$type, 'null']] + $constraintSchema],
                            ]]],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ]]],
                ];
                $parsed = $parser->parse(json_encode($document, JSON_THROW_ON_ERROR), ApiSpecFormat::Json);
                $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

                $oracle = (new Validator)->validate(
                    json_decode('{"value":null}', false, 512, JSON_THROW_ON_ERROR),
                    json_decode(json_encode($document['paths']['/values']['post']['requestBody']['content']['application/json']['schema'], JSON_THROW_ON_ERROR)),
                );
                $this->assertTrue($oracle->isValid(), "Oracle must accept nullable {$type} {$constraint}");

                foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
                    $cases = $generator->generateForSpec($spec, [TestTechnique::HappyPath]);
                    $this->assertCount(1, $cases, "{$type} {$constraint} should produce a HAPPY_PATH");
                    $this->assertSame(['value' => null], $cases[0]['steps'][0]['request']['body']);
                    $result = (new DslValidator)->validate($cases[0], $parsed);
                    $this->assertTrue($result->isValid, implode('; ', $result->errors));
                }

                $schema = ['type' => [$type, 'null']] + $constraintSchema;
                $this->assertTrue($generator->isValueConformingToSchema(null, $schema));
                $this->assertFalse($generator->isValueConformingToSchema(null, ['type' => $type]));
                $this->assertFalse($generator->isValueConformingToSchema(2, $schema));

                $endpoint = $parsed->endpoints[0]->toArray();
                $missingRequiredProperty = [
                    'path_params' => [],
                    'query' => [],
                    'headers' => [],
                    'body' => ['other' => 'present'],
                    'has_body' => true,
                ];
                $this->assertFalse($generator->isRequestConformingToEndpoint($missingRequiredProperty, $endpoint));
            }
        }
    }

    public function test_scalar_all_of_happy_paths_conform_to_every_branch(): void
    {
        $schemas = [
            ['allOf' => [['type' => 'integer'], ['minimum' => 1, 'maximum' => 10]]],
            ['allOf' => [['minimum' => 1, 'maximum' => 10], ['type' => 'integer']]],
            ['allOf' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 100], ['minimum' => 90], ['maximum' => 95]]],
            ['minimum' => 7, 'allOf' => [['type' => 'integer', 'minimum' => 1], ['maximum' => 10]]],
            ['allOf' => [['type' => 'number'], ['exclusiveMinimum' => 0.1], ['exclusiveMaximum' => 0.9]]],
            ['allOf' => [['type' => 'number', 'minimum' => 1, 'exclusiveMinimum' => true], ['minimum' => 5, 'maximum' => 5]]],
            ['allOf' => [['type' => 'number'], ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]]],
            ['allOf' => [['type' => 'integer'], ['allOf' => [['minimum' => 1], ['maximum' => 10]]]]],
            ['allOf' => [['type' => 'object', 'required' => ['quantity'], 'properties' => ['quantity' => ['type' => 'integer', 'minimum' => 90]]], ['properties' => ['quantity' => ['maximum' => 100]]]]],
        ];
        foreach ($schemas as $schema) {
            $endpoint = $this->endpoint($schema);
            $generator = new RuleGenerator;
            $cases = $generator->generateHappyPath($endpoint);
            $this->assertCount(1, $cases, json_encode($schema, JSON_THROW_ON_ERROR));
            $this->assertPayloadMatchesSchema($cases[0]['steps'][0]['request']['body'], $schema);
            $this->assertSame(201, $cases[0]['steps'][0]['assertions'][0]['equals']);
            $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
            $this->assertTrue($result->isValid, implode('; ', $result->errors));
        }
    }

    public function test_scalar_one_of_happy_paths_conform_to_exactly_one_branch(): void
    {
        $schemas = [
            ['oneOf' => [['type' => 'integer'], ['type' => 'number']]],
            ['oneOf' => [['type' => 'number'], ['type' => 'integer']]],
            ['oneOf' => [['type' => 'integer', 'maximum' => 5], ['type' => 'integer', 'minimum' => 10]]],
        ];

        foreach ($schemas as $schema) {
            $endpoint = $this->endpoint($schema);
            $generator = new RuleGenerator;
            $cases = $generator->generateHappyPath($endpoint);
            $this->assertCount(1, $cases, json_encode($schema, JSON_THROW_ON_ERROR));
            $body = $cases[0]['steps'][0]['request']['body'];
            $this->assertPayloadMatchesSchema($body, $schema);
            $this->assertSame(201, $cases[0]['steps'][0]['assertions'][0]['equals']);

            // For [integer, number] union, body must NOT be an integer because an integer matches both branches
            $branchTypes = array_column($schema['oneOf'], 'type');
            if (in_array('integer', $branchTypes, true) && in_array('number', $branchTypes, true)) {
                $this->assertFalse(is_int($body), 'Body for oneOf [integer, number] must not be an integer');
            }

            $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
            $this->assertTrue($result->isValid, implode('; ', $result->errors));
        }

        // Impossible oneOf: both branches match all integers, so exactly-one is impossible
        $impossibleSchema = ['oneOf' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 1], ['type' => 'integer', 'minimum' => 1, 'maximum' => 1]]];
        $generator = new RuleGenerator;
        $impossibleCases = $generator->generateHappyPath($this->endpoint($impossibleSchema));
        $this->assertEmpty($impossibleCases, 'Impossible oneOf must not emit success case');
    }

    public function test_equivalence_error_oracles_require_a_nonconforming_numeric_value(): void
    {
        foreach ([
            [['type' => 'integer', 'minimum' => -10000, 'maximum' => -1], true],
            [['type' => 'number', 'minimum' => -10000.5, 'maximum' => -0.5], true],
            [['type' => 'integer', 'minimum' => -10000], true],
            [['type' => 'number', 'maximum' => 10000], true],
            [['type' => 'integer', 'enum' => [-1, 0, 1]], true],
            [['type' => 'number', 'enum' => [-1, 0, 1]], true],
            [['type' => 'integer', 'multipleOf' => 2], true],
            [['type' => 'integer'], false],
            [['type' => 'number'], false],
            [['type' => 'integer', 'multipleOf' => 1], false],
        ] as [$field, $hasInvalidPartition]) {
            $schema = ['type' => 'object', 'required' => ['quantity'], 'properties' => ['quantity' => $field]];
            $endpoint = $this->endpoint($schema);
            $generator = new RuleGenerator;
            $cases = $generator->generateEquivalence($endpoint);
            $this->assertNotEmpty($cases, json_encode($field, JSON_THROW_ON_ERROR));
            $invalidCount = 0;
            foreach ($cases as $case) {
                $payload = $case['steps'][0]['request']['body'];
                $isSuccess = $case['steps'][0]['assertions'][0]['equals'] === 201;
                $validation = (new Validator)->validate(
                    json_decode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
                );
                $this->assertSame($isSuccess, $validation->isValid(), $case['title'].' '.json_encode($payload));
                $this->assertSame($isSuccess, $generator->isValueConformingToSchema($payload, $schema));
                $dsl = (new DslValidator)->validate($case, ['endpoints' => [$endpoint]]);
                $this->assertTrue($dsl->isValid, implode('; ', $dsl->errors));
                $invalidCount += $isSuccess ? 0 : 1;
            }
            $this->assertSame($hasInvalidPartition, $invalidCount > 0, json_encode($field, JSON_THROW_ON_ERROR));
        }
    }

    public function test_impossible_all_of_constraints_do_not_emit_success_oracles(): void
    {
        foreach ([
            ['allOf' => [['type' => 'integer', 'minimum' => 10], ['maximum' => 1]]],
            ['allOf' => [['type' => 'integer'], ['type' => 'string']]],
            ['allOf' => [['type' => 'integer', 'enum' => [1]], ['enum' => [2]]]],
        ] as $field) {
            $generator = new RuleGenerator;
            $this->assertSame([], $generator->generateHappyPath($this->endpoint($field)));
            $schema = ['type' => 'object', 'properties' => ['quantity' => $field], 'required' => ['quantity']];
            $cases = $generator->generate($this->endpoint($schema), [TestTechnique::HappyPath, TestTechnique::Boundary, TestTechnique::Equivalence]);
            foreach ($cases as $case) {
                $this->assertSame(422, $case['steps'][0]['assertions'][0]['equals']);
            }
        }
    }

    public function test_endpoints_without_two_xx_responses_emit_only_error_cases(): void
    {
        $schema = ['type' => 'object', 'required' => ['quantity'], 'properties' => [
            'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5, 'enum' => ['book', 'toy']],
            'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'integer']],
        ]];
        foreach ([['422' => []], ['default' => []], ['4XX' => []], ['5XX' => []], []] as $responses) {
            $endpoint = $this->endpoint($schema);
            $endpoint['responses'] = $responses;
            $endpoint['parameters'] = [['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]]];
            $generator = new RuleGenerator;
            $this->assertNull($generator->getSuccessStatusCode($endpoint));
            $this->assertSame([], $generator->generateHappyPath($endpoint));
            $cases = $generator->generate($endpoint, [TestTechnique::Boundary, TestTechnique::Equivalence]);
            if (isset($responses['422']) || isset($responses['default']) || isset($responses['4XX'])) {
                $this->assertNotEmpty($cases);
            } else {
                $this->assertSame([], $cases);
            }
            foreach ($cases as $case) {
                $this->assertStringNotContainsString('Biên hợp lệ:', $case['title']);
                $this->assertStringNotContainsString('Phân hoạch tương đương hợp lệ:', $case['title']);
                $this->assertSame(422, $case['steps'][0]['assertions'][0]['equals']);
                $result = (new DslValidator)->validate($case, ['endpoints' => [$endpoint]]);
                $this->assertTrue($result->isValid, implode('; ', $result->errors));
            }
        }
    }

    public function test_success_oracles_use_declared_numeric_or_wildcard_two_xx_responses(): void
    {
        foreach ([
            [['2XX' => [], '422' => []], 201],
            [['202' => [], '422' => []], 202],
            [['299' => [], 'default' => []], 299],
        ] as [$responses, $status]) {
            $endpoint = $this->endpoint(['type' => 'integer', 'minimum' => 1, 'maximum' => 10]);
            $endpoint['responses'] = $responses;
            $generator = new RuleGenerator;
            $this->assertSame($status, $generator->getSuccessStatusCode($endpoint));
            $cases = $generator->generateHappyPath($endpoint);
            $this->assertCount(1, $cases);
            $this->assertSame($status, $cases[0]['steps'][0]['assertions'][0]['equals']);
            $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
            $this->assertTrue($result->isValid, implode('; ', $result->errors));
        }
    }

    public function test_all_six_techniques_mutate_schema_inputs_and_pass_semantic_validation(): void
    {
        $schema = ['type' => 'object', 'required' => ['quantity', 'kind'], 'properties' => [
            'quantity' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 8],
            'kind' => ['type' => 'string', 'enum' => ['book', 'toy']],
        ]];
        $endpoint = $this->endpoint($schema);
        $endpoint['path'] = '/items/{id}';
        $endpoint['parameters'] = [
            ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['in' => 'query', 'name' => 'limit', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]],
        ];
        $endpoint['responses'] = ['202' => ['description' => 'Accepted'], '400' => ['description' => 'Invalid']];
        $spec = ['openapi' => '3.0.3', 'paths' => [$endpoint['path'] => ['post' => [
            'parameters' => $endpoint['parameters'], 'requestBody' => $endpoint['request_body'], 'responses' => $endpoint['responses'],
        ]]]];
        $generator = new RuleGenerator;
        $baseline = $generator->generateHappyPath($endpoint)[0]['steps'][0]['request'];
        foreach (RuleGenerator::SUPPORTED_TECHNIQUES as $technique) {
            $cases = $generator->generate($endpoint, [$technique]);
            $this->assertNotEmpty($cases, $technique->value);
            $mutated = false;
            foreach ($cases as $case) {
                $result = (new DslValidator)->validate($case, $spec);
                $this->assertTrue($result->isValid, $case['title'].': '.implode('; ', $result->errors));
                $request = $case['steps'][0]['request'];
                $status = $case['steps'][0]['assertions'][0]['equals'];
                if (str_contains($case['title'], 'tham số đường dẫn')) {
                    // S10-01 r2: path parameters get their own TYPE_MISMATCH case (rejected with 400).
                    $this->assertNotSame($baseline['path_params'], $request['path_params']);
                    $this->assertSame(400, $status);
                    $mutated = true;

                    continue;
                }
                $this->assertSame($baseline['path_params'], $request['path_params']);
                $this->assertContains($status, [202, 400]);
                if ($status === 202) {
                    $this->assertPayloadMatchesSchema($request['body'], $schema);
                    $this->assertPayloadMatchesSchema($request['query']['limit'], $endpoint['parameters'][1]['schema']);
                }
                $mutated = $mutated || $request['body'] !== $baseline['body'] || $request['query'] !== $baseline['query'];
            }
            if ($technique !== TestTechnique::HappyPath) {
                $this->assertTrue($mutated, $technique->value.' must change an input from the happy path');
            }
        }
    }

    public function test_numeric_enum_boundaries_never_assert_success_for_disallowed_values(): void
    {
        $schema = ['type' => 'object', 'required' => ['quantity'], 'properties' => [
            'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'enum' => [2, 8]],
        ]];
        $cases = (new RuleGenerator)->generateBoundary($this->endpoint($schema));
        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            if ($case['steps'][0]['assertions'][0]['equals'] === 201) {
                $this->assertPayloadMatchesSchema($case['steps'][0]['request']['body'], $schema);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function endpoint(array $schema): array
    {
        return [
            'method' => 'POST',
            'path' => '/items',
            'parameters' => [],
            'request_body' => ['content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Invalid']],
        ];
    }

    private function hydrateParsedSpec(ParsedOpenApi $parsed): ApiSpec
    {
        $spec = new ApiSpec;
        $spec->setRawAttributes([
            'normalized' => json_encode($parsed->normalized, JSON_THROW_ON_ERROR),
        ], true);
        $endpoints = [];
        foreach ($parsed->endpoints as $parsedEndpoint) {
            $data = $parsedEndpoint->toArray();
            foreach (['parameters', 'request_body', 'responses', 'security'] as $attribute) {
                if (isset($data[$attribute])) {
                    $data[$attribute] = json_encode($data[$attribute], JSON_THROW_ON_ERROR);
                }
            }
            $endpoint = new Endpoint;
            $endpoint->setRawAttributes($data, true);
            $endpoints[] = $endpoint;
        }
        $spec->setRelation('endpoints', new Collection($endpoints));

        return $spec;
    }

    /** @param array<string, mixed> $schema */
    private function assertPayloadMatchesSchema(mixed $payload, array $schema): void
    {
        $result = (new Validator)->validate(
            json_decode(json_encode($payload, JSON_THROW_ON_ERROR)),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
        );
        $this->assertTrue($result->isValid(), 'Payload violates schema: '.json_encode($payload));
    }

    public function test_normalized_components_resolve_for_all_spec_and_endpoint_inputs(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        $normalized = ['components' => ['schemas' => ['Input' => $schema]]];
        $data = $this->endpoint(['$ref' => '#/components/schemas/Input']);
        $parsedEndpoint = new ParsedEndpoint(
            method: HttpMethod::Post,
            path: '/items',
            requestBody: $data['request_body'],
            responses: $data['responses'],
        );
        $parsed = new ParsedOpenApi(true, ApiSpecFormat::Json, normalized: $normalized, endpoints: [$parsedEndpoint]);
        $spec = new ApiSpec(['normalized' => $normalized]);
        $endpoint = new Endpoint($data);
        $endpoint->setRelation('apiSpec', $spec);
        $spec->setRelation('endpoints', new Collection([$endpoint]));
        $generator = new RuleGenerator;

        foreach ([$parsed, $spec, new Collection([$endpoint]), ['normalized' => $normalized, 'endpoints' => [$data]]] as $input) {
            $cases = $generator->generateForSpec($input, [TestTechnique::HappyPath]);
            $this->assertCount(1, $cases);
            $this->assertPayloadMatchesSchema($cases[0]['steps'][0]['request']['body'], $schema);
        }
        $cases = $generator->generate($endpoint, [TestTechnique::HappyPath]);
        $this->assertCount(1, $cases);
        $this->assertPayloadMatchesSchema($cases[0]['steps'][0]['request']['body'], $schema);
    }

    public function test_empty_objects_and_arrays_remain_distinct_in_generated_payloads(): void
    {
        $generator = new RuleGenerator;
        foreach ([
            ['type' => 'object'],
            ['type' => 'object', 'properties' => ['child' => ['type' => 'object'], 'list' => ['type' => 'array', 'maxItems' => 0]]],
            ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'object']],
        ] as $schema) {
            $cases = $generator->generateHappyPath($this->endpoint($schema));
            $this->assertCount(1, $cases);
            $body = $cases[0]['steps'][0]['request']['body'];
            $this->assertPayloadMatchesSchema($body, $schema);
        }
        $this->assertFalse($generator->isValueConformingToSchema([], ['type' => 'object']));
        $this->assertTrue($generator->isValueConformingToSchema(new \stdClass, ['type' => 'object']));
        $this->assertFalse($generator->isValueConformingToSchema(new \stdClass, ['type' => 'array']));
    }

    public function test_omitting_last_required_property_keeps_an_object(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        $cases = (new RuleGenerator)->generateMissingRequired($this->endpoint($schema));

        $this->assertCount(1, $cases);
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertSame('{}', json_encode($body));
        $this->assertPayloadMatchesSchema($body, ['type' => 'object']);
        $this->assertSame(422, $cases[0]['steps'][0]['assertions'][0]['equals']);
        $this->assertTrue((new DslValidator)->validate($cases[0])->isValid);
    }

    public function test_fractional_integer_bounds_produce_valid_body_and_query_boundaries(): void
    {
        $field = ['type' => 'integer', 'minimum' => 1.5, 'maximum' => 2.5];
        $schema = ['type' => 'object', 'properties' => ['quantity' => $field], 'required' => ['quantity']];
        $endpoint = $this->endpoint($schema);
        $endpoint['parameters'] = [['in' => 'query', 'name' => 'quantity', 'required' => true, 'schema' => $field]];
        $cases = (new RuleGenerator)->generateBoundary($endpoint);
        $successes = 0;

        foreach ($cases as $case) {
            if ($case['steps'][0]['assertions'][0]['equals'] !== 201) {
                continue;
            }
            $successes++;
            $request = $case['steps'][0]['request'];
            $this->assertSame(2, $request['body']['quantity']);
            $this->assertSame(2, $request['query']['quantity']);
            $this->assertPayloadMatchesSchema($request['body'], $schema);
            $this->assertPayloadMatchesSchema($request['query']['quantity'], $field);
        }
        $this->assertSame(4, $successes);
    }

    public function test_numeric_multiples_conform_in_happy_boundary_and_equivalence_cases(): void
    {
        $generator = new RuleGenerator;
        foreach ([
            ['type' => 'number', 'minimum' => 1, 'maximum' => 10, 'multipleOf' => 2],
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'multipleOf' => 2],
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'multipleOf' => 1.5],
            ['type' => 'integer', 'minimum' => -10, 'maximum' => -1, 'multipleOf' => 3],
            ['type' => 'number', 'minimum' => 0.1, 'maximum' => 0.5, 'multipleOf' => 0.1, 'exclusiveMinimum' => true, 'exclusiveMaximum' => true],
        ] as $field) {
            $schema = ['type' => 'object', 'properties' => ['quantity' => $field], 'required' => ['quantity']];
            $endpoint = $this->endpoint($schema);
            foreach ([TestTechnique::HappyPath, TestTechnique::Boundary, TestTechnique::Equivalence] as $technique) {
                $cases = $generator->generate($endpoint, [$technique]);
                $successes = 0;
                foreach ($cases as $case) {
                    if ($case['steps'][0]['assertions'][0]['equals'] === 201) {
                        $successes++;
                        $this->assertPayloadMatchesSchema($case['steps'][0]['request']['body'], $schema);
                    }
                }
                $this->assertGreaterThan(0, $successes, 'Missing conforming '.$technique->value.' case');
            }
        }
        $this->assertFalse($generator->isValueConformingToSchema(5.5, ['type' => 'number', 'multipleOf' => 2]));
        $this->assertFalse($generator->isValueConformingToSchema(5, ['type' => 'integer', 'multipleOf' => 2]));
    }

    public function test_impossible_numeric_constraints_do_not_emit_success_cases(): void
    {
        $field = ['type' => 'integer', 'minimum' => 1, 'maximum' => 1, 'multipleOf' => 2];
        $schema = ['type' => 'object', 'properties' => ['quantity' => $field]];
        $cases = (new RuleGenerator)->generate($this->endpoint($schema), [TestTechnique::HappyPath, TestTechnique::Boundary, TestTechnique::Equivalence]);
        foreach ($cases as $case) {
            $this->assertSame(422, $case['steps'][0]['assertions'][0]['equals']);
        }
    }

    public function test_required_headers_survive_every_technique_and_json_content_type(): void
    {
        $schema = ['type' => 'object', 'properties' => ['quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]], 'required' => ['quantity']];
        $endpoint = $this->endpoint($schema);
        $endpoint['parameters'] = [['in' => 'header', 'name' => 'X-Tenant-ID', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['tenant-a']]]];
        $generator = new RuleGenerator;
        foreach (RuleGenerator::SUPPORTED_TECHNIQUES as $technique) {
            $cases = $generator->generate($endpoint, [$technique]);
            $this->assertNotEmpty($cases, $technique->value);
            foreach ($cases as $case) {
                if ($technique === TestTechnique::MissingRequired && str_contains($case['title'], "Thiếu header bắt buộc 'X-Tenant-ID'")) {
                    $this->assertArrayNotHasKey('X-Tenant-ID', $case['steps'][0]['request']['headers'] ?? []);
                    $this->assertSame(422, $case['steps'][0]['assertions'][0]['equals']);

                    continue;
                }
                $this->assertSame('tenant-a', $case['steps'][0]['request']['headers']['X-Tenant-ID']);
                $this->assertSame('application/json', $case['steps'][0]['request']['headers']['Content-Type']);
            }
        }
        $endpoint['method'] = 'GET';
        $endpoint['request_body'] = null;
        $cases = $generator->generateHappyPath($endpoint);
        $this->assertSame(['X-Tenant-ID' => 'tenant-a'], $cases[0]['steps'][0]['request']['headers']);
    }

    public function test_long_paths_keep_dsl_step_names_within_schema_limit(): void
    {
        foreach (['/'.str_repeat('a', 250), '/'.str_repeat('đ', 250)] as $path) {
            $cases = (new RuleGenerator)->generateHappyPath(['method' => 'GET', 'path' => $path, 'responses' => ['200' => ['description' => 'OK']]]);
            $this->assertCount(1, $cases);
            $this->assertSame($path, $cases[0]['steps'][0]['request']['path']);
            $this->assertLessThanOrEqual(255, mb_strlen($cases[0]['steps'][0]['name']));
        }
    }

    public function test_type_mismatch_with_all_json_types_union_skips_and_never_emits_fallback_object(): void
    {
        $generator = new RuleGenerator;

        $unionsCoveringAllTypes = [
            ['type' => ['string', 'integer', 'number', 'boolean', 'array', 'object']],
            ['type' => ['string', 'number', 'boolean', 'array', 'object']],
            ['oneOf' => [
                ['type' => 'string'],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'array'],
                ['type' => 'object'],
            ]],
            ['anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'boolean'],
                ['type' => 'array'],
                ['type' => 'object'],
            ]],
        ];

        foreach ($unionsCoveringAllTypes as $unionSchema) {
            $this->assertNull(
                $generator->generateMismatchedValue($unionSchema),
                'Union covering all JSON types must return null, not an object fallback'
            );

            $endpoint = [
                'method' => 'POST',
                'path' => '/flexible-items',
                'request_body' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['payload'],
                                'properties' => [
                                    'payload' => $unionSchema,
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '201' => ['description' => 'Created'],
                    '422' => ['description' => 'Validation error'],
                ],
            ];

            $cases = $generator->generateTypeMismatch($endpoint);
            $this->assertEmpty(
                $cases,
                'Must not emit TYPE_MISMATCH case when union field permits every JSON type'
            );
        }

        // When another field can be mismatched, only the invalidatable field emits a test case
        $multiFieldEndpoint = [
            'method' => 'POST',
            'path' => '/flexible-items',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['payload', 'count'],
                            'properties' => [
                                'payload' => ['type' => ['string', 'number', 'boolean', 'array', 'object']],
                                'count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Validation error'],
            ],
        ];

        $cases = $generator->generateTypeMismatch($multiFieldEndpoint);
        $this->assertCount(1, $cases);
        $this->assertSame("Sai kiểu dữ liệu của trường 'count' trong POST /flexible-items", $cases[0]['title']);
        $this->assertArrayNotHasKey('__fallback_type_mismatch__', (array) ($cases[0]['steps'][0]['request']['body']['payload'] ?? []));
    }

    public function test_one_of_numeric_happy_path_requires_single_branch_match_and_never_emits_multimatch_payload(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'integer'],
                ['type' => 'number'],
            ],
        ];
        $endpoint = $this->endpoint($schema);
        $generator = new RuleGenerator;

        // 1 is both integer and number, violating oneOf
        $this->assertFalse(
            $generator->isValueConformingToSchema(1, $schema),
            'Integer 1 matches both integer and number branches, so it must violate oneOf'
        );

        $cases = $generator->generateHappyPath($endpoint);
        $this->assertCount(1, $cases);
        $payload = $cases[0]['steps'][0]['request']['body'];
        $this->assertTrue(
            $generator->isValueConformingToSchema($payload, $schema),
            'Generated happy path payload must strictly conform to oneOf schema: '.json_encode($payload)
        );
        $this->assertNotSame(1, $payload, 'Payload must not be integer 1');
        $this->assertSame(201, $cases[0]['steps'][0]['assertions'][0]['equals']);

        $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_equivalence_and_invalid_input_do_not_use_sentinels_when_declared_in_enum(): void
    {
        $enumWithSentinels = ['active', 'inactive', 'INVALID_ENUM_PARTITION', 'DISALLOWED_ENUM_OPTION_XYZ'];
        $schema = [
            'type' => 'object',
            'required' => ['status'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => $enumWithSentinels,
                ],
            ],
        ];
        $endpoint = $this->endpoint($schema);
        $generator = new RuleGenerator;

        // Both sentinels are valid values under this schema
        $this->assertTrue($generator->isValueConformingToSchema('INVALID_ENUM_PARTITION', $schema['properties']['status']));
        $this->assertTrue($generator->isValueConformingToSchema('DISALLOWED_ENUM_OPTION_XYZ', $schema['properties']['status']));

        // Generate EQUIVALENCE
        $equivCases = $generator->generateEquivalence($endpoint);
        $hasErrorCase = false;
        foreach ($equivCases as $case) {
            $statusAssert = $case['steps'][0]['assertions'][0]['equals'];
            $body = (array) $case['steps'][0]['request']['body'];
            if ($statusAssert === 422) {
                $hasErrorCase = true;
                $this->assertNotSame('INVALID_ENUM_PARTITION', $body['status']);
                $this->assertNotSame('DISALLOWED_ENUM_OPTION_XYZ', $body['status']);
                $this->assertFalse(
                    $generator->isValueConformingToSchema($body['status'], $schema['properties']['status']),
                    'Mutated value in 422 case must truly violate enum schema'
                );
            }
        }
        $this->assertTrue($hasErrorCase, 'Must have at least one invalid partition error case');

        // Generate INVALID_INPUT
        $invalidCases = $generator->generateInvalidInput($endpoint);
        $this->assertNotEmpty($invalidCases);
        foreach ($invalidCases as $case) {
            $body = (array) $case['steps'][0]['request']['body'];
            $this->assertNotSame('INVALID_ENUM_PARTITION', $body['status']);
            $this->assertNotSame('DISALLOWED_ENUM_OPTION_XYZ', $body['status']);
            $this->assertFalse(
                $generator->isValueConformingToSchema($body['status'], $schema['properties']['status']),
                'Mutated value in INVALID_INPUT case must truly violate enum schema'
            );
        }
    }

    public function test_happy_path_verifies_path_query_header_schemas_and_skips_impossible_constraints(): void
    {
        $generator = new RuleGenerator;
        $impossibleSchema = ['type' => 'integer', 'minimum' => 10, 'maximum' => 1];

        // 1. Path param impossible
        $epPath = [
            'method' => 'GET',
            'path' => '/items/{id}',
            'parameters' => [
                ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => $impossibleSchema],
            ],
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $this->assertSame([], $generator->generateHappyPath($epPath), 'Must skip happy path when path param has impossible constraints');

        // 2. Query param impossible
        $epQuery = [
            'method' => 'GET',
            'path' => '/items',
            'parameters' => [
                ['in' => 'query', 'name' => 'limit', 'required' => true, 'schema' => $impossibleSchema],
            ],
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $this->assertSame([], $generator->generateHappyPath($epQuery), 'Must skip happy path when query param has impossible constraints');

        // 3. Header param impossible
        $epHeader = [
            'method' => 'GET',
            'path' => '/items',
            'parameters' => [
                ['in' => 'header', 'name' => 'X-Limit', 'required' => true, 'schema' => $impossibleSchema],
            ],
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $this->assertSame([], $generator->generateHappyPath($epHeader), 'Must skip happy path when header param has impossible constraints');

        // 4. Request body impossible
        $epBody = [
            'method' => 'POST',
            'path' => '/items',
            'request_body' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['count'],
                            'properties' => ['count' => $impossibleSchema],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $this->assertSame([], $generator->generateHappyPath($epBody), 'Must skip happy path when body has impossible constraints');
    }

    public function test_missing_required_generates_case_for_required_headers(): void
    {
        $ep = [
            'method' => 'POST',
            'path' => '/orders',
            'parameters' => [
                [
                    'in' => 'header',
                    'name' => 'X-API-Key',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                [
                    'in' => 'query',
                    'name' => 'source',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
            ],
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['item'],
                            'properties' => ['item' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];

        $generator = new RuleGenerator;
        $cases = $generator->generateMissingRequired($ep);

        $headerCases = array_values(array_filter(
            $cases,
            fn ($c) => str_contains($c['title'], "Thiếu header bắt buộc 'X-API-Key'")
        ));

        $this->assertCount(1, $headerCases);
        $headerCase = $headerCases[0];
        $this->assertSame(422, $headerCase['steps'][0]['assertions'][0]['equals']);
        $this->assertArrayNotHasKey('X-API-Key', $headerCase['steps'][0]['request']['headers'] ?? []);

        $result = (new DslValidator)->validate($headerCase, ['endpoints' => [$ep]]);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_impossible_parameter_constraints_do_not_emit_happy_path_success_cases(): void
    {
        $impossibleNumericSchema = ['type' => 'integer', 'minimum' => 10, 'maximum' => 1];
        $generator = new RuleGenerator;

        // 1. Path parameter with impossible constraint
        $epPath = [
            'method' => 'GET',
            'path' => '/items/{id}',
            'parameters' => [
                [
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => $impossibleNumericSchema,
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];
        $happyCasesPath = $generator->generateHappyPath($epPath);
        $this->assertEmpty($happyCasesPath, 'Must NOT emit happy path case when path param constraint is impossible');
        $allCasesPath = $generator->generate($epPath, RuleGenerator::SUPPORTED_TECHNIQUES);
        foreach ($allCasesPath as $case) {
            $status = $case['steps'][0]['assertions'][0]['equals'];
            $this->assertGreaterThanOrEqual(400, $status, 'Must NOT emit 2xx success case for impossible path constraint');
        }

        // 2. Query parameter with impossible constraint
        $epQuery = [
            'method' => 'GET',
            'path' => '/items',
            'parameters' => [
                [
                    'in' => 'query',
                    'name' => 'limit',
                    'required' => true,
                    'schema' => $impossibleNumericSchema,
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];
        $happyCasesQuery = $generator->generateHappyPath($epQuery);
        $this->assertEmpty($happyCasesQuery, 'Must NOT emit happy path case when query param constraint is impossible');
        $allCasesQuery = $generator->generate($epQuery, RuleGenerator::SUPPORTED_TECHNIQUES);
        foreach ($allCasesQuery as $case) {
            $status = $case['steps'][0]['assertions'][0]['equals'];
            $this->assertGreaterThanOrEqual(400, $status, 'Must NOT emit 2xx success case for impossible query constraint');
        }

        // 3. Header parameter with impossible constraint
        $epHeader = [
            'method' => 'GET',
            'path' => '/items',
            'parameters' => [
                [
                    'in' => 'header',
                    'name' => 'X-Rate-Limit',
                    'required' => true,
                    'schema' => $impossibleNumericSchema,
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];
        $happyCasesHeader = $generator->generateHappyPath($epHeader);
        $this->assertEmpty($happyCasesHeader, 'Must NOT emit happy path case when header param constraint is impossible');
        $allCasesHeader = $generator->generate($epHeader, RuleGenerator::SUPPORTED_TECHNIQUES);
        foreach ($allCasesHeader as $case) {
            $status = $case['steps'][0]['assertions'][0]['equals'];
            $this->assertGreaterThanOrEqual(400, $status, 'Must NOT emit 2xx success case for impossible header constraint');
        }
    }

    public function test_happy_path_verifies_path_query_and_header_parameters_according_to_schema(): void
    {
        $generator = new RuleGenerator;
        $ep = [
            'method' => 'POST',
            'path' => '/departments/{dept_id}/employees',
            'parameters' => [
                [
                    'in' => 'path',
                    'name' => 'dept_id',
                    'required' => true,
                    'schema' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 50],
                ],
                [
                    'in' => 'query',
                    'name' => 'role',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['admin', 'manager', 'engineer']],
                ],
                [
                    'in' => 'header',
                    'name' => 'X-Request-Seq',
                    'required' => true,
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999],
                ],
            ],
            'has_request_body' => true,
            'request_body' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];

        $cases = $generator->generateHappyPath($ep);
        $this->assertCount(1, $cases);

        $case = $cases[0];
        $req = $case['steps'][0]['request'];

        // Path param verified
        $this->assertArrayHasKey('dept_id', $req['path_params']);
        $this->assertGreaterThanOrEqual(10, $req['path_params']['dept_id']);
        $this->assertLessThanOrEqual(50, $req['path_params']['dept_id']);

        // Query param verified
        $this->assertArrayHasKey('role', $req['query']);
        $this->assertContains($req['query']['role'], ['admin', 'manager', 'engineer']);

        // Header param verified
        $this->assertArrayHasKey('X-Request-Seq', $req['headers']);
        $this->assertGreaterThanOrEqual(1, (int) $req['headers']['X-Request-Seq']);
        $this->assertLessThanOrEqual(999, (int) $req['headers']['X-Request-Seq']);

        // Body verified
        $this->assertIsArray($req['body']);
        $this->assertArrayHasKey('name', $req['body']);

        $res = (new DslValidator)->validate($case, ['endpoints' => [$ep]]);
        $this->assertTrue($res->isValid, implode('; ', $res->errors));
    }

    public function test_example_violating_schema_is_ignored_and_valid_happy_path_is_generated(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'example' => ['unexpected' => 'x'],
        ];

        $generator = new RuleGenerator;

        // Internal oracle tests
        $this->assertFalse(
            $generator->isValueConformingToSchema(['unexpected' => 'x'], $schema),
            'Object missing required field and with disallowed additionalProperties must be non-conforming'
        );
        $this->assertFalse(
            $generator->isValueConformingToSchema(['name' => 'Alice', 'unexpected' => 'x'], $schema),
            'Object with disallowed additionalProperties must be non-conforming'
        );
        $this->assertTrue(
            $generator->isValueConformingToSchema(['name' => 'Alice'], $schema),
            'Object conforming to schema must be conforming'
        );

        $endpoint = $this->endpoint($schema);
        $cases = $generator->generateHappyPath($endpoint);

        $this->assertCount(1, $cases);
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($body);
        $this->assertArrayHasKey('name', $body);
        $this->assertArrayNotHasKey('unexpected', $body);

        $this->assertPayloadMatchesSchema($body, $schema);

        $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_ref_sibling_constraints_are_preserved_and_invalid_component_examples_are_ignored(): void
    {
        $components = [
            'ShortCode' => [
                'type' => 'string',
                'example' => 'ABC',
            ],
        ];

        $schema = [
            '$ref' => '#/components/schemas/ShortCode',
            'maxLength' => 2,
        ];

        $generator = new RuleGenerator;
        $resolved = $generator->resolveSchema($schema, $components);
        $this->assertSame(2, $resolved['maxLength']);
        $this->assertSame('string', $resolved['type']);

        $endpoint = [
            'method' => 'POST',
            'path' => '/codes',
            'parameters' => [],
            'request_body' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $schema,
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Unprocessable'],
            ],
        ];

        $spec = [
            'endpoints' => [$endpoint],
            'components' => ['schemas' => $components],
        ];

        $cases = $generator->generateHappyPath($endpoint, $components);
        $this->assertCount(1, $cases);

        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertIsString($body);
        $this->assertNotSame('ABC', $body, 'Component example exceeding sibling maxLength must not be used');
        $this->assertLessThanOrEqual(2, strlen($body));

        $schemaWithComponents = $schema;
        $schemaWithComponents['components'] = ['schemas' => $components];
        $this->assertPayloadMatchesSchema($body, $schemaWithComponents);

        $result = (new DslValidator)->validate($cases[0], $spec);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_unique_array_items_preserve_multiple_of_constraint(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                'type' => 'integer',
                'multipleOf' => 2,
            ],
        ];

        $generator = new RuleGenerator;
        $endpoint = $this->endpoint($schema);
        $cases = $generator->generateHappyPath($endpoint);

        $this->assertCount(1, $cases, 'Must generate happy path for unique array with multipleOf');
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($body);
        $this->assertGreaterThanOrEqual(3, count($body));
        $this->assertSame(count($body), count(array_unique($body)), 'All array items must be unique');

        foreach ($body as $item) {
            $this->assertIsInt($item);
            $this->assertSame(0, $item % 2, "Item {$item} must be a multiple of 2");
        }

        $this->assertPayloadMatchesSchema($body, $schema);

        $result = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_ref_sibling_constraints_are_conjoined_and_cannot_loosen_component(): void
    {
        $generator = new RuleGenerator;

        // 1. Repeated maxLength: component maxLength 2, sibling maxLength 5, example 'ABC'
        $schema1 = ['$ref' => '#/components/schemas/Code', 'maxLength' => 5];
        $components1 = ['Code' => ['type' => 'string', 'maxLength' => 2, 'example' => 'ABC']];
        $ep1 = $this->endpoint($schema1);
        $cases1 = $generator->generateHappyPath($ep1, $components1);
        $this->assertCount(1, $cases1);
        $body1 = $cases1[0]['steps'][0]['request']['body'];
        $this->assertIsString($body1);
        $this->assertLessThanOrEqual(2, strlen($body1));
        $this->assertPayloadMatchesSchema($body1, array_merge($schema1, ['components' => ['schemas' => $components1]]));

        // 2. Repeated minimum: component minimum 10, sibling minimum 1, example 2
        $schema2 = ['$ref' => '#/components/schemas/Num', 'minimum' => 1];
        $components2 = ['Num' => ['type' => 'integer', 'minimum' => 10, 'example' => 2]];
        $ep2 = $this->endpoint($schema2);
        $cases2 = $generator->generateHappyPath($ep2, $components2);
        $this->assertCount(1, $cases2);
        $body2 = $cases2[0]['steps'][0]['request']['body'];
        $this->assertGreaterThanOrEqual(10, $body2);
        $this->assertPayloadMatchesSchema($body2, array_merge($schema2, ['components' => ['schemas' => $components2]]));

        // 3. Repeated property: component age minimum 10, sibling age minimum 1, example age: 2
        $schema3 = ['$ref' => '#/components/schemas/Obj', 'properties' => ['age' => ['type' => 'integer', 'minimum' => 1]]];
        $components3 = ['Obj' => ['type' => 'object', 'required' => ['age'], 'properties' => ['age' => ['type' => 'integer', 'minimum' => 10]], 'example' => ['age' => 2]]];
        $ep3 = $this->endpoint($schema3);
        $cases3 = $generator->generateHappyPath($ep3, $components3);
        $this->assertCount(1, $cases3);
        $body3 = $cases3[0]['steps'][0]['request']['body'];
        $this->assertGreaterThanOrEqual(10, $body3['age']);
        $this->assertPayloadMatchesSchema($body3, array_merge($schema3, ['components' => ['schemas' => $components3]]));

        // 4. Repeated enum: component enum ['A'], sibling enum ['A', 'B'], example 'B'
        $schema4 = ['$ref' => '#/components/schemas/Code', 'enum' => ['A', 'B']];
        $components4 = ['Code' => ['type' => 'string', 'enum' => ['A'], 'example' => 'B']];
        $ep4 = $this->endpoint($schema4);
        $cases4 = $generator->generateHappyPath($ep4, $components4);
        $this->assertCount(1, $cases4);
        $body4 = $cases4[0]['steps'][0]['request']['body'];
        $this->assertSame('A', $body4);
        $this->assertPayloadMatchesSchema($body4, array_merge($schema4, ['components' => ['schemas' => $components4]]));
    }

    public function test_parameter_preserves_original_constraints_for_const_not_and_ref(): void
    {
        $generator = new RuleGenerator;
        $components = ['Num' => ['type' => 'integer', 'minimum' => 10, 'example' => 2]];

        foreach (['query', 'path', 'header'] as $location) {
            foreach ([
                'const' => ['type' => 'integer', 'const' => 3],
                'not' => ['type' => 'integer', 'not' => ['const' => 1]],
                'ref' => ['$ref' => '#/components/schemas/Num', 'minimum' => 1],
            ] as $kind => $schema) {
                $ep = $this->endpoint(['type' => 'object']);
                $ep['parameters'] = [['in' => $location, 'name' => 'value', 'required' => true, 'schema' => $schema]];
                if ($location === 'path') {
                    $ep['path'] = '/items/{value}';
                }

                $cases = $generator->generateHappyPath($ep, $components);
                $this->assertNotEmpty($cases, "Must generate happy path for {$location}-{$kind}");

                $key = ['query' => 'query', 'header' => 'headers', 'path' => 'path_params'][$location];
                $val = (int) $cases[0]['steps'][0]['request'][$key]['value'];

                $schemaWithComp = array_merge($schema, ['components' => ['schemas' => $components]]);
                $this->assertPayloadMatchesSchema($val, $schemaWithComp);

                if ($kind === 'const') {
                    $this->assertSame(3, $val);
                } elseif ($kind === 'not') {
                    $this->assertNotSame(1, $val);
                } elseif ($kind === 'ref') {
                    $this->assertGreaterThanOrEqual(10, $val);
                }
            }
        }
    }

    public function test_unique_numeric_arrays_tight_bounds_and_narrow_exclusive(): void
    {
        $generator = new RuleGenerator;

        // Tight positive integer: [100, 104] step 2
        $schemaPos = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 104, 'multipleOf' => 2],
        ];
        $casesPos = $generator->generateHappyPath($this->endpoint($schemaPos));
        $this->assertCount(1, $casesPos);
        $bodyPos = $casesPos[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $bodyPos);
        $this->assertSame(3, count(array_unique($bodyPos)));
        $this->assertPayloadMatchesSchema($bodyPos, $schemaPos);

        // Tight negative integer: [-104, -100] step 2
        $schemaNeg = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => ['type' => 'integer', 'minimum' => -104, 'maximum' => -100, 'multipleOf' => 2],
        ];
        $casesNeg = $generator->generateHappyPath($this->endpoint($schemaNeg));
        $this->assertCount(1, $casesNeg);
        $bodyNeg = $casesNeg[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $bodyNeg);
        $this->assertSame(3, count(array_unique($bodyNeg)));
        $this->assertPayloadMatchesSchema($bodyNeg, $schemaNeg);

        // Tight narrow exclusive number
        $schemaExc = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => ['type' => 'number', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 0.001, 'multipleOf' => 0.0001],
        ];
        $casesExc = $generator->generateHappyPath($this->endpoint($schemaExc));
        $this->assertCount(1, $casesExc);
        $bodyExc = $casesExc[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $bodyExc);
        $this->assertSame(3, count(array_unique($bodyExc)));
        $this->assertPayloadMatchesSchema($bodyExc, $schemaExc);
    }

    public function test_numeric_enum_filters_against_all_constraints_and_picks_valid_witness(): void
    {
        $generator = new RuleGenerator;
        $schema = ['type' => 'integer', 'enum' => [1, 2], 'multipleOf' => 2];
        $cases = $generator->generateHappyPath($this->endpoint($schema));
        $this->assertCount(1, $cases);
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertSame(2, $body);
        $this->assertPayloadMatchesSchema($body, $schema);
    }

    public function test_one_of_object_preserves_parent_required_and_properties(): void
    {
        $generator = new RuleGenerator;
        $schema = [
            'type' => 'object',
            'required' => ['tenant'],
            'properties' => ['tenant' => ['type' => 'string']],
            'oneOf' => [
                ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ];
        $cases = $generator->generateHappyPath($this->endpoint($schema));
        $this->assertCount(1, $cases);
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertArrayHasKey('tenant', $body);
        $this->assertTrue(isset($body['name']) xor isset($body['id']), 'Matches exactly one branch');
        $this->assertPayloadMatchesSchema($body, $schema);
    }

    public function test_path_parameter_with_hyphen_generates_valid_dsl(): void
    {
        $generator = new RuleGenerator;
        $ep = $this->endpoint(['type' => 'object']);
        $ep['path'] = '/items/{item-id}';
        $ep['parameters'] = [['in' => 'path', 'name' => 'item-id', 'required' => true, 'schema' => ['type' => 'integer']]];

        $cases = $generator->generateHappyPath($ep);
        $this->assertCount(1, $cases);
        $this->assertArrayHasKey('item-id', $cases[0]['steps'][0]['request']['path_params']);

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [
                '/items/{item-id}' => [
                    'post' => [
                        'parameters' => $ep['parameters'],
                        'requestBody' => $ep['request_body'],
                        'responses' => $ep['responses'],
                    ],
                ],
            ],
        ];
        $dsl = (new DslValidator)->validate($cases[0], $spec);
        $this->assertTrue($dsl->isValid, implode('; ', $dsl->errors));
    }

    public function test_regression_ref_sibling_allof_is_preserved_and_passes_dsl_validator(): void
    {
        $schema = [
            '$ref' => '#/components/schemas/Base',
            'allOf' => [
                [
                    'type' => 'object',
                    'required' => ['b'],
                    'properties' => ['b' => ['type' => 'string']],
                ],
            ],
        ];
        $components = [
            'Base' => [
                'type' => 'object',
                'required' => ['a'],
                'properties' => ['a' => ['type' => 'string']],
            ],
        ];

        $generator = new RuleGenerator;
        $ep = $this->endpoint($schema);
        $cases = $generator->generateHappyPath($ep, $components);
        $this->assertCount(1, $cases);

        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertArrayHasKey('a', $body);
        $this->assertArrayHasKey('b', $body);

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [
                '/items' => [
                    'post' => [
                        'requestBody' => $ep['request_body'],
                        'responses' => $ep['responses'],
                    ],
                ],
            ],
            'components' => ['schemas' => $components],
        ];

        $dsl = (new DslValidator)->validate($cases[0], $spec);
        $this->assertTrue($dsl->isValid, implode('; ', $dsl->errors));
    }

    public function test_regression_ref_multiple_conjunction_calculates_lcm(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                '$ref' => '#/components/schemas/Num',
                'multipleOf' => 3,
            ],
        ];
        $components = [
            'Num' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 12,
                'multipleOf' => 2,
            ],
        ];

        $generator = new RuleGenerator;
        $ep = $this->endpoint($schema);
        $cases = $generator->generateHappyPath($ep, $components);
        $this->assertCount(1, $cases);

        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body);
        $this->assertCount(3, array_unique($body));
        foreach ($body as $item) {
            $this->assertSame(0, $item % 2, "Item {$item} must be a multiple of 2");
            $this->assertSame(0, $item % 3, "Item {$item} must be a multiple of 3");
            $this->assertGreaterThanOrEqual(0, $item);
            $this->assertLessThanOrEqual(12, $item);
        }
    }

    public function test_regression_unique_integer_with_large_and_fractional_step(): void
    {
        $generator = new RuleGenerator;

        // Large integer multipleOf step
        $schema1 = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 3003,
                'multipleOf' => 1001,
            ],
        ];
        $cases1 = $generator->generateHappyPath($this->endpoint($schema1));
        $this->assertCount(1, $cases1);
        $body1 = $cases1[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body1);
        $this->assertCount(3, array_unique($body1));

        // Fractional multipleOf for integer
        $schema2 = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 3003,
                'multipleOf' => 500.5,
            ],
        ];
        $cases2 = $generator->generateHappyPath($this->endpoint($schema2));
        $this->assertCount(1, $cases2);
        $body2 = $cases2[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body2);
        $this->assertCount(3, array_unique($body2));
    }

    public function test_regression_unique_number_with_tiny_ranges(): void
    {
        $generator = new RuleGenerator;

        // Tiny step with multipleOf
        $schema1 = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                'type' => 'number',
                'exclusiveMinimum' => 0,
                'exclusiveMaximum' => 0.000000004,
                'multipleOf' => 0.000000001,
            ],
        ];
        $cases1 = $generator->generateHappyPath($this->endpoint($schema1));
        $this->assertCount(1, $cases1);
        $body1 = $cases1[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body1);
        $this->assertCount(3, array_unique($body1));
        foreach ($body1 as $val) {
            $this->assertGreaterThan(0, $val);
            $this->assertLessThan(0.000000004, $val);
        }

        // Tiny range without multipleOf
        $schema2 = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => [
                'type' => 'number',
                'exclusiveMinimum' => 0,
                'exclusiveMaximum' => 0.000000004,
            ],
        ];
        $cases2 = $generator->generateHappyPath($this->endpoint($schema2));
        $this->assertCount(1, $cases2);
        $body2 = $cases2[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body2);
        $this->assertCount(3, array_unique($body2));
        foreach ($body2 as $val) {
            $this->assertGreaterThan(0, $val);
            $this->assertLessThan(0.000000004, $val);
        }
    }

    public function test_regression_integer_lattice_with_large_numerator_fractional_multiple_of(): void
    {
        $generator = new RuleGenerator;
        $oracle = new Validator;

        // Scalar integer with fractional multipleOf 1.0000001
        $scalarSchema = [
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 30000003,
            'multipleOf' => 1.0000001,
        ];
        $scalarVal = $generator->generateValidValueForSchema($scalarSchema, []);
        $this->assertIsInt($scalarVal);
        $this->assertTrue($oracle->validate($scalarVal, json_decode((string) json_encode($scalarSchema)))->isValid());

        // Unique array integer with fractional multipleOf 1.0000001
        $arraySchema = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => $scalarSchema,
        ];
        $endpoint = $this->endpoint($arraySchema);
        $cases = $generator->generateHappyPath($endpoint);
        $this->assertCount(1, $cases);
        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($body);
        $this->assertCount(3, $body);
        $this->assertCount(3, array_unique($body));
        $this->assertTrue($oracle->validate($body, json_decode((string) json_encode($arraySchema)))->isValid());
        $dslResult = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
        $this->assertTrue($dslResult->isValid, implode('; ', $dslResult->errors));
    }

    public function test_regression_lcm_large_integer_and_fractional_beyond_12_decimals(): void
    {
        $generator = new RuleGenerator;
        $oracle = new Validator;

        // 1. Large integer ref LCM: 2000000001 and 2000000101 -> LCM = 4000000204000000101
        $largeIntComponents = [
            'Num' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 4000000204000000101,
                'multipleOf' => 2000000001,
            ],
        ];
        $largeIntItems = [
            '$ref' => '#/components/schemas/Num',
            'multipleOf' => 2000000101,
        ];
        $largeIntArraySchema = [
            'type' => 'array',
            'minItems' => 2,
            'uniqueItems' => true,
            'items' => $largeIntItems,
        ];
        $largeIntEndpoint = [
            'method' => 'POST',
            'path' => '/items',
            'parameters' => [],
            'request_body' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => $largeIntArraySchema]],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $largeIntSpec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/items' => ['post' => ['requestBody' => $largeIntEndpoint['request_body'], 'responses' => $largeIntEndpoint['responses']]]],
            'components' => ['schemas' => $largeIntComponents],
        ];

        // Without example
        $cases1 = $generator->generateHappyPath($largeIntEndpoint, $largeIntComponents);
        $this->assertCount(1, $cases1);
        $body1 = $cases1[0]['steps'][0]['request']['body'];
        $this->assertCount(2, $body1);
        $this->assertCount(2, array_unique($body1));
        $fullLargeIntSchema = $largeIntArraySchema + ['components' => ['schemas' => $largeIntComponents]];
        $this->assertTrue($oracle->validate($body1, json_decode((string) json_encode($fullLargeIntSchema)))->isValid());
        $dsl1 = (new DslValidator)->validate($cases1[0], $largeIntSpec);
        $this->assertTrue($dsl1->isValid, implode('; ', $dsl1->errors));

        // With explicit example witness
        $witnessLargeInt = [0, 4000000204000000101];
        $largeIntArrayWithEx = $largeIntArraySchema + ['example' => $witnessLargeInt];
        $endpointWithEx = $largeIntEndpoint;
        $endpointWithEx['request_body']['content']['application/json']['schema'] = $largeIntArrayWithEx;
        $cases1Ex = $generator->generateHappyPath($endpointWithEx, $largeIntComponents);
        $this->assertCount(1, $cases1Ex);
        $this->assertEquals($witnessLargeInt, $cases1Ex[0]['steps'][0]['request']['body']);

        // 2. Fractional ref LCM beyond 12 decimals: 0.0000010000001 and 0.0000010000003 -> LCM = 10.0000040000003
        $fracComponents = [
            'Num' => [
                'type' => 'number',
                'minimum' => 0,
                'maximum' => 20.0000080000006,
                'multipleOf' => 0.0000010000001,
            ],
        ];
        $fracItems = [
            '$ref' => '#/components/schemas/Num',
            'multipleOf' => 0.0000010000003,
        ];
        $fracArraySchema = [
            'type' => 'array',
            'minItems' => 3,
            'uniqueItems' => true,
            'items' => $fracItems,
        ];
        $fracEndpoint = [
            'method' => 'POST',
            'path' => '/items',
            'parameters' => [],
            'request_body' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => $fracArraySchema]],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $fracSpec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/items' => ['post' => ['requestBody' => $fracEndpoint['request_body'], 'responses' => $fracEndpoint['responses']]]],
            'components' => ['schemas' => $fracComponents],
        ];

        $cases2 = $generator->generateHappyPath($fracEndpoint, $fracComponents);
        $this->assertCount(1, $cases2);
        $body2 = $cases2[0]['steps'][0]['request']['body'];
        $this->assertCount(3, $body2);
        $this->assertCount(3, array_unique($body2));
        $fullFracSchema = $fracArraySchema + ['components' => ['schemas' => $fracComponents]];
        $this->assertTrue($oracle->validate($body2, json_decode((string) json_encode($fullFracSchema)))->isValid());
        $dsl2 = (new DslValidator)->validate($cases2[0], $fracSpec);
        $this->assertTrue($dsl2->isValid, implode('; ', $dsl2->errors));

        // With explicit example witness
        $witnessFrac = [0, 10.0000040000003, 20.0000080000006];
        $fracArrayWithEx = $fracArraySchema + ['example' => $witnessFrac];
        $fracEndpointWithEx = $fracEndpoint;
        $fracEndpointWithEx['request_body']['content']['application/json']['schema'] = $fracArrayWithEx;
        $cases2Ex = $generator->generateHappyPath($fracEndpointWithEx, $fracComponents);
        $this->assertCount(1, $cases2Ex);
        $this->assertEquals($witnessFrac, $cases2Ex[0]['steps'][0]['request']['body']);
    }

    public function test_regression_unique_number_narrow_range_with_offsets(): void
    {
        $generator = new RuleGenerator;
        $oracle = new Validator;

        foreach ([
            [1000000.0, 1000000.000000004],
            [10.0, 10.0000000000004],
        ] as [$lower, $upper]) {
            $schema = [
                'type' => 'array',
                'minItems' => 3,
                'uniqueItems' => true,
                'items' => [
                    'type' => 'number',
                    'exclusiveMinimum' => $lower,
                    'exclusiveMaximum' => $upper,
                ],
            ];
            $endpoint = $this->endpoint($schema);
            $cases = $generator->generateHappyPath($endpoint);
            $this->assertCount(1, $cases);
            $body = $cases[0]['steps'][0]['request']['body'];
            $this->assertCount(3, $body);
            $this->assertCount(3, array_unique($body, SORT_REGULAR));
            foreach ($body as $val) {
                $this->assertGreaterThan($lower, $val);
                $this->assertLessThan($upper, $val);
            }
            $this->assertTrue($oracle->validate($body, json_decode((string) json_encode($schema)))->isValid());
            $dsl = (new DslValidator)->validate($cases[0], ['endpoints' => [$endpoint]]);
            $this->assertTrue($dsl->isValid, implode('; ', $dsl->errors));
        }
    }

    public function test_object_and_array_literals_in_const_and_enum_preserve_types_for_parser_and_model(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;
        $validator = new DslValidator;
        $oracle = new Validator;

        $constraints = ['const', 'enum'];
        $types = ['object', 'array'];
        $shapes = ['empty', 'numeric', 'filled'];
        $runners = ['parser', 'model'];

        foreach ($constraints as $constraint) {
            foreach ($types as $type) {
                foreach ($shapes as $shape) {
                    if ($type === 'object') {
                        $literal = match ($shape) {
                            'empty' => new \stdClass,
                            'numeric' => (object) ['0' => 'a', '1' => 'b'],
                            'filled' => (object) ['a' => 1],
                        };
                    } else {
                        $literal = match ($shape) {
                            'empty' => [],
                            'numeric' => ['a', 'b'],
                            'filled' => [(object) ['a' => 1]],
                        };
                    }

                    $propertySchema = ['type' => $type];
                    if ($constraint === 'const') {
                        $propertySchema['const'] = $literal;
                    } else {
                        $propertySchema['enum'] = [$literal];
                    }

                    $doc = [
                        'openapi' => '3.1.0',
                        'info' => ['title' => "Matrix {$constraint} {$type} {$shape}", 'version' => '1.0.0'],
                        'paths' => [
                            '/values' => [
                                'post' => [
                                    'responses' => [
                                        '200' => ['description' => 'ok'],
                                        '400' => ['description' => 'bad'],
                                    ],
                                    'requestBody' => [
                                        'required' => true,
                                        'content' => [
                                            'application/json' => [
                                                'schema' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'value' => $propertySchema,
                                                    ],
                                                    'required' => ['value'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];

                    $jsonDoc = json_encode($doc, JSON_THROW_ON_ERROR);
                    $parsed = $parser->parse($jsonDoc, ApiSpecFormat::Json);
                    $this->assertTrue($parsed->isValid, "Parser failed for {$constraint} {$type} {$shape}: ".implode('; ', $parsed->errors));

                    // Verify original schema oracle validates the witness
                    $expectedBody = ['value' => $literal];
                    $bodySchema = $doc['paths']['/values']['post']['requestBody']['content']['application/json']['schema'];
                    $oracleResult = $oracle->validate(
                        json_decode(json_encode($expectedBody, JSON_THROW_ON_ERROR)),
                        json_decode(json_encode($bodySchema, JSON_THROW_ON_ERROR)),
                    );
                    $this->assertTrue($oracleResult->isValid(), "Original oracle must validate witness for {$constraint} {$type} {$shape}");

                    foreach ($runners as $runner) {
                        if ($runner === 'parser') {
                            $specToRun = $parsed;
                        } else {
                            $specToRun = $this->hydrateParsedSpec($parsed);
                        }

                        $cases = $generator->generateForSpec($specToRun, [TestTechnique::HappyPath]);
                        $this->assertCount(1, $cases, "{$constraint} x {$type} x {$shape} x {$runner} must produce exactly 1 HAPPY_PATH case");

                        $dslResult = $validator->validate($cases[0], $parsed);
                        $this->assertTrue($dslResult->isValid, "DSL validation failed for {$constraint} {$type} {$shape} {$runner}: ".implode('; ', $dslResult->errors));
                    }
                }
            }
        }
    }

    public function test_yaml_object_literal_representation_preserves_empty_and_numeric_keys(): void
    {
        $generator = new RuleGenerator;
        $parser = new OpenApiParser;
        $validator = new DslValidator;

        $yaml = <<<'YAML'
openapi: "3.1.0"
info:
  title: YAML Object Literal Representation
  version: "1.0.0"
paths:
  /values:
    post:
      responses:
        "200":
          description: ok
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                empty_obj:
                  type: object
                  const: {}
                numeric_obj:
                  type: object
                  const:
                    "0": "a"
                    "1": "b"
                empty_enum:
                  type: object
                  enum:
                    - {}
                numeric_enum:
                  type: object
                  enum:
                    - "0": "a"
                      "1": "b"
              required:
                - empty_obj
                - numeric_obj
                - empty_enum
                - numeric_enum
YAML;

        $parsed = $parser->parse($yaml, ApiSpecFormat::Yaml);
        $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

        foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
            $cases = $generator->generateForSpec($spec, [TestTechnique::HappyPath]);
            $this->assertCount(1, $cases);

            $dslResult = $validator->validate($cases[0], $parsed);
            $this->assertTrue($dslResult->isValid, implode('; ', $dslResult->errors));

            $body = $cases[0]['steps'][0]['request']['body'];
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('"empty_obj":{}', $encoded);
            $this->assertStringContainsString('"numeric_obj":{"0":"a","1":"b"}', $encoded);
            $this->assertStringContainsString('"empty_enum":{}', $encoded);
            $this->assertStringContainsString('"numeric_enum":{"0":"a","1":"b"}', $encoded);
        }
    }

    public function test_schema_names_matching_literal_keywords_remain_structural_maps(): void
    {
        $parser = new OpenApiParser;
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Structural keyword names', 'version' => '1'],
            'paths' => ['/values' => ['post' => [
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['const', 'enum'],
                        'properties' => [
                            'const' => ['$ref' => '#/components/schemas/const'],
                            'enum' => ['$ref' => '#/components/schemas/enum'],
                        ],
                    ]]],
                ],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
            'components' => ['schemas' => [
                'const' => ['type' => 'string', 'minLength' => 1],
                'enum' => ['type' => 'integer', 'minimum' => 1],
            ]],
        ];

        $parsed = $parser->parse(json_encode($document, JSON_THROW_ON_ERROR), ApiSpecFormat::Json);

        $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));
        foreach ([$parsed, $this->hydrateParsedSpec($parsed)] as $spec) {
            $cases = (new RuleGenerator)->generateForSpec($spec, [TestTechnique::HappyPath]);
            $this->assertCount(1, $cases);
            $this->assertTrue((new DslValidator)->validate($cases[0], $parsed)->isValid);
        }
    }

    public function test_json_object_equality_ignores_property_order_and_compares_nested_numbers_semantically(): void
    {
        $generator = new RuleGenerator;
        $candidate = json_decode('{"a":1.0,"b":[2]}', false, 512, JSON_THROW_ON_ERROR);
        $sameJsonValue = json_decode('{"b":[2.0],"a":1}', false, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($generator->isValueConformingToSchema(
            $candidate,
            ['type' => 'object', 'const' => $sameJsonValue],
        ));
        $this->assertTrue($generator->isValueConformingToSchema(
            $candidate,
            ['type' => 'object', 'enum' => [$sameJsonValue]],
        ));
        $this->assertFalse($generator->isValueConformingToSchema(
            $candidate,
            ['type' => 'object', 'const' => json_decode('{"a":"1","b":[2]}', false, 512, JSON_THROW_ON_ERROR)],
        ));
    }
}
