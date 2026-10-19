<?php

namespace Tests\Unit\Services;

use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Enums\TestCaseSource;
use App\Enums\TestTechnique;
use App\Models\Endpoint;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleGenerator;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\TestCase;

class RuleGeneratorTest extends TestCase
{
    private RuleGenerator $generator;

    private DslValidator $validator;

    private OpenApiParser $parser;

    private ParsedOpenApi $simpleStoreSpec;

    private ParsedOpenApi $petstoreSpec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RuleGenerator;
        $this->validator = new DslValidator;
        $this->parser = new OpenApiParser;

        $simpleStorePath = dirname(__DIR__, 4).'/benchmark/simple-store/openapi.yaml';
        $this->simpleStoreSpec = $this->parser->parse((string) file_get_contents($simpleStorePath), ApiSpecFormat::Yaml);

        $petstorePath = dirname(__DIR__, 2).'/Fixtures/OpenApi/petstore-3.0.json';
        $this->petstoreSpec = $this->parser->parse((string) file_get_contents($petstorePath), ApiSpecFormat::Json);
    }

    /**
     * Find a parsed endpoint by method and path.
     *
     * @return array<string, mixed>
     */
    protected function getEndpoint(ParsedOpenApi $spec, string $method, string $path): array
    {
        foreach ($spec->endpoints as $ep) {
            if ($ep->method->value === strtoupper($method) && $ep->path === $path) {
                return $ep->toArray();
            }
        }

        $this->fail("Endpoint {$method} {$path} not found in spec.");
    }

    public function test_happy_path_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateHappyPath($ep, $schemas);

        $this->assertCount(1, $cases);
        $case = $cases[0];

        $this->assertSame('1.0', $case['dsl_version']);
        $this->assertSame(TestTechnique::HappyPath->value, $case['technique']);
        $this->assertSame(TestCaseSource::Rule->value, $case['source']);
        $this->assertSame('POST', $case['endpoint']['method']);
        $this->assertSame('/products', $case['endpoint']['path']);
        $this->assertNotEmpty($case['steps']);

        // Assert 100% valid with DslValidator
        $res = $this->validator->validate($case, $this->simpleStoreSpec);
        $this->assertTrue($res->isValid, 'Happy path failed validation: '.implode('; ', $res->errors));
    }

    public function test_missing_required_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateMissingRequired($ep, $schemas);

        // ProductCreate has 4 required fields: name, sku, price, category_id
        $this->assertGreaterThanOrEqual(4, count($cases));

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::MissingRequired->value, $case['technique']);
            $this->assertSame(TestCaseSource::Rule->value, $case['source']);
            $this->assertSame('POST', $case['endpoint']['method']);
            $this->assertSame('/products', $case['endpoint']['path']);

            // Validate against DslValidator
            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Missing required case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_missing_required_header_generates_valid_dsl(): void
    {
        $endpoint = [
            'method' => 'GET',
            'path' => '/api/secure-data',
            'parameters' => [
                [
                    'name' => 'X-API-KEY',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'X-Trace-ID',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'X-Optional',
                    'in' => 'header',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Unprocessable Entity'],
            ],
        ];

        $cases = $this->generator->generateMissingRequired($endpoint);

        $this->assertCount(2, $cases);

        // First case omits X-API-KEY and preserves X-Trace-ID
        $apiKeyCase = collect($cases)->firstWhere('title', "Thiếu header bắt buộc 'X-API-KEY' trong GET /api/secure-data");
        $this->assertNotNull($apiKeyCase);
        $this->assertSame(TestTechnique::MissingRequired->value, $apiKeyCase['technique']);
        $this->assertArrayNotHasKey('X-API-KEY', $apiKeyCase['steps'][0]['request']['headers'] ?? []);
        $this->assertArrayHasKey('X-Trace-ID', $apiKeyCase['steps'][0]['request']['headers'] ?? []);
        $this->assertSame(422, $apiKeyCase['steps'][0]['assertions'][0]['equals']);

        // Second case omits X-Trace-ID and preserves X-API-KEY
        $traceCase = collect($cases)->firstWhere('title', "Thiếu header bắt buộc 'X-Trace-ID' trong GET /api/secure-data");
        $this->assertNotNull($traceCase);
        $this->assertSame(TestTechnique::MissingRequired->value, $traceCase['technique']);
        $this->assertArrayNotHasKey('X-Trace-ID', $traceCase['steps'][0]['request']['headers'] ?? []);
        $this->assertArrayHasKey('X-API-KEY', $traceCase['steps'][0]['request']['headers'] ?? []);
        $this->assertSame(422, $traceCase['steps'][0]['assertions'][0]['equals']);

        foreach ($cases as $case) {
            $res = $this->validator->validate($case);
            $this->assertTrue($res->isValid, 'Case failed validation: '.implode('; ', $res->errors));
        }
    }

    public function test_type_mismatch_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateTypeMismatch($ep, $schemas);

        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::TypeMismatch->value, $case['technique']);
            $this->assertSame(TestCaseSource::Rule->value, $case['source']);

            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Type mismatch case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_boundary_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateBoundary($ep, $schemas);

        // ProductCreate has min/max length, min price, min/max quantity
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::Boundary->value, $case['technique']);
            $this->assertSame(TestCaseSource::Rule->value, $case['source']);

            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Boundary case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_equivalence_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateEquivalence($ep, $schemas);

        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::Equivalence->value, $case['technique']);
            $this->assertSame(TestCaseSource::Rule->value, $case['source']);

            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Equivalence case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_invalid_input_technique_generates_valid_dsl(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateInvalidInput($ep, $schemas);

        // sku has pattern ^[A-Z0-9_-]+$
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::InvalidInput->value, $case['technique']);
            $this->assertSame(TestCaseSource::Rule->value, $case['source']);

            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Invalid input case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_boundary_technique_with_query_parameters(): void
    {
        // GET /categories has limit (min: 1, max: 100), offset (min: 0)
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'GET', '/categories');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generateBoundary($ep, $schemas);

        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(TestTechnique::Boundary->value, $case['technique']);
            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Query boundary case '{$case['title']}' failed: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_one_hundred_percent_generated_cases_are_valid_for_entire_benchmark_spec(): void
    {
        $allCases = $this->generator->generateForSpec($this->simpleStoreSpec);

        $this->assertGreaterThan(10, count($allCases), 'Should generate substantial test suite for simple-store.');

        $techniqueCounts = [];

        foreach ($allCases as $case) {
            $tech = $case['technique'];
            $techniqueCounts[$tech] = ($techniqueCounts[$tech] ?? 0) + 1;

            $res = $this->validator->validate($case, $this->simpleStoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Generated case '{$case['title']}' ({$tech}) failed validation: ".implode('; ', $res->errors)
            );
        }

        // Verify that all 6 required techniques are present
        $this->assertArrayHasKey('HAPPY_PATH', $techniqueCounts);
        $this->assertArrayHasKey('MISSING_REQUIRED', $techniqueCounts);
        $this->assertArrayHasKey('TYPE_MISMATCH', $techniqueCounts);
        $this->assertArrayHasKey('BOUNDARY', $techniqueCounts);
        $this->assertArrayHasKey('EQUIVALENCE', $techniqueCounts);
        $this->assertArrayHasKey('INVALID_INPUT', $techniqueCounts);
    }

    public function test_one_hundred_percent_generated_cases_are_valid_for_petstore_spec(): void
    {
        $allCases = $this->generator->generateForSpec($this->petstoreSpec);

        $this->assertNotEmpty($allCases);

        foreach ($allCases as $case) {
            $res = $this->validator->validate($case, $this->petstoreSpec);
            $this->assertTrue(
                $res->isValid,
                "Petstore generated case '{$case['title']}' failed validation: ".implode('; ', $res->errors)
            );
        }
    }

    public function test_filter_techniques_parameter(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generate($ep, [TestTechnique::HappyPath], $schemas);

        $this->assertCount(1, $cases);
        $this->assertSame(TestTechnique::HappyPath->value, $cases[0]['technique']);
    }

    public function test_alias_namespace_rule_generator_works_identically(): void
    {
        $aliasGenerator = new \App\Services\Rule\RuleGenerator;
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'GET', '/health');

        $cases = $aliasGenerator->generate($ep);

        $this->assertCount(1, $cases);
        $this->assertSame(TestTechnique::HappyPath->value, $cases[0]['technique']);
        $this->assertSame('GET', $cases[0]['endpoint']['method']);
        $this->assertSame('/health', $cases[0]['endpoint']['path']);
    }

    public function test_generate_accepts_parsed_endpoint_instance(): void
    {
        $parsedEp = $this->simpleStoreSpec->endpoints[0];
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        $cases = $this->generator->generate($parsedEp, [TestTechnique::HappyPath], $schemas);

        $this->assertCount(1, $cases);
        $res = $this->validator->validate($cases[0], $this->simpleStoreSpec);
        $this->assertTrue($res->isValid);
    }

    public function test_generate_accepts_endpoint_eloquent_model_instance(): void
    {
        $parsedEp = $this->simpleStoreSpec->endpoints[0];
        $model = new Endpoint([
            'method' => $parsedEp->method,
            'path' => $parsedEp->path,
            'parameters' => $parsedEp->parameters,
            'request_body' => $parsedEp->requestBody,
            'responses' => $parsedEp->responses,
        ]);

        $cases = $this->generator->generate($model, [TestTechnique::HappyPath]);

        $this->assertCount(1, $cases);
        $res = $this->validator->validate($cases[0], $this->simpleStoreSpec);
        $this->assertTrue($res->isValid);
    }

    public function test_regression_error_status_code_selection_prioritizes_422_over_404(): void
    {
        $ep = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];

        // In PHP, array keys that are numeric strings become integers: [201 => ..., 400 => ..., 404 => ..., 409 => ..., 422 => ...]
        $status = $this->generator->getErrorStatusCode($ep);
        $this->assertSame(422, $status, 'Error status code should prioritize 422 for validation error rejection.');

        $missingCases = $this->generator->generateMissingRequired($ep, $schemas);
        $this->assertNotEmpty($missingCases);
        foreach ($missingCases as $case) {
            $this->assertSame(422, $case['steps'][0]['assertions'][0]['equals']);
        }
    }

    public function test_regression_openapi_31_nullable_array_type_does_not_throw(): void
    {
        $endpointWithNullable = [
            'method' => 'POST',
            'path' => '/items',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['title', 'count'],
                            'properties' => [
                                'title' => ['type' => ['string', 'null']],
                                'count' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 50],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Unprocessable Entity'],
            ],
        ];

        // Ensure extractSchemaType handles array types
        $this->assertSame('string', $this->generator->extractSchemaType(['type' => ['string', 'null']]));
        $this->assertSame('integer', $this->generator->extractSchemaType(['type' => ['integer', 'null']]));

        // Generate all techniques - should not throw "Array to string conversion" ErrorException
        $allCases = $this->generator->generate($endpointWithNullable);
        $this->assertNotEmpty($allCases);

        $techniques = array_column($allCases, 'technique');
        $this->assertContains(TestTechnique::HappyPath->value, $techniques);
        $this->assertContains(TestTechnique::MissingRequired->value, $techniques);
        $this->assertContains(TestTechnique::TypeMismatch->value, $techniques);
        $this->assertContains(TestTechnique::Boundary->value, $techniques);
        $this->assertContains(TestTechnique::Equivalence->value, $techniques);
    }

    public function test_regression_scalar_request_body_preserves_primitive_type(): void
    {
        $scalarEndpoint = [
            'method' => 'POST',
            'path' => '/scores',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 100,
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Validation error'],
            ],
        ];

        $happyCases = $this->generator->generateHappyPath($scalarEndpoint);
        $this->assertCount(1, $happyCases);
        // Scalar integer should NOT be wrapped in ['value' => 1]
        $this->assertSame(50, $happyCases[0]['steps'][0]['request']['body']);

        $mismatchCases = $this->generator->generateTypeMismatch($scalarEndpoint);
        $this->assertNotEmpty($mismatchCases);
        $this->assertSame('this_is_an_invalid_string_not_number', $mismatchCases[0]['steps'][0]['request']['body']);
    }

    public function test_regression_happy_path_avoids_seed_data_collision_for_slug_and_sku(): void
    {
        // 1. POST /categories has examples: ['electronics', 'home-appliances']
        $catEp = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/categories');
        $schemas = $this->simpleStoreSpec->components['schemas'] ?? [];
        $catCases = $this->generator->generateHappyPath($catEp, $schemas);

        $this->assertCount(1, $catCases);
        $catBody = $catCases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($catBody);
        $this->assertNotSame('electronics', $catBody['slug'], 'Should not use seeded example "electronics"');
        $this->assertNotSame('home-appliances', $catBody['slug'], 'Should not use seeded example "home-appliances"');
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $catBody['slug']);

        // 2. POST /products has examples: ['PROD-001', 'TECH_100']
        $prodEp = $this->getEndpoint($this->simpleStoreSpec, 'POST', '/products');
        $prodCases = $this->generator->generateHappyPath($prodEp, $schemas);

        $this->assertCount(1, $prodCases);
        $prodBody = $prodCases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($prodBody);
        $this->assertNotSame('PROD-001', $prodBody['sku'], 'Should not use seeded example "PROD-001"');
        $this->assertMatchesRegularExpression('/^[A-Z0-9_-]+$/', $prodBody['sku']);
    }

    public function test_regression_slug_and_sku_respect_constraints_and_are_unique_across_cases(): void
    {
        // 1. Slug with maxLength: 5 must not exceed 5 chars and must match slug pattern
        $endpointWithMaxLengthSlug = [
            'method' => 'POST',
            'path' => '/custom-cats',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['slug'],
                            'properties' => [
                                'slug' => [
                                    'type' => 'string',
                                    'maxLength' => 5,
                                    'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Error']],
        ];
        $catCases = $this->generator->generateHappyPath($endpointWithMaxLengthSlug);
        $slugVal = $catCases[0]['steps'][0]['request']['body']['slug'];
        $this->assertLessThanOrEqual(5, strlen($slugVal), 'Slug must respect maxLength: 5');
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugVal);

        // 2. SKU with pattern ^PROD-[0-9]{3}$ must conform to pattern
        $endpointWithPatternSku = [
            'method' => 'POST',
            'path' => '/custom-prods',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['sku'],
                            'properties' => [
                                'sku' => [
                                    'type' => 'string',
                                    'pattern' => '^PROD-[0-9]{3}$',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Error']],
        ];
        $prodCases = $this->generator->generateHappyPath($endpointWithPatternSku);
        $skuVal = $prodCases[0]['steps'][0]['request']['body']['sku'];
        $this->assertMatchesRegularExpression('/^PROD-[0-9]{3}$/', $skuVal);

        // 3. Unique fields across multiple cases in suite do not collide
        $case1 = $this->generator->generateHappyPath($endpointWithPatternSku);
        $case2 = $this->generator->generateHappyPath($endpointWithPatternSku);
        $this->assertNotSame(
            $case1[0]['steps'][0]['request']['body']['sku'],
            $case2[0]['steps'][0]['request']['body']['sku'],
            'Successive cases must use distinct unique values to avoid 409 DB collision'
        );
    }

    public function test_regression_type_mismatch_for_union_types_selects_type_outside_allowed_set(): void
    {
        $endpointWithUnion = [
            'method' => 'POST',
            'path' => '/flexible-items',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['code'],
                            'properties' => [
                                'code' => [
                                    'type' => ['string', 'integer'],
                                ],
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

        $cases = $this->generator->generateTypeMismatch($endpointWithUnion);
        $this->assertNotEmpty($cases);
        $sentCode = $cases[0]['steps'][0]['request']['body']['code'];

        // Must NOT be string and must NOT be integer
        $this->assertIsNotString($sentCode, 'Mismatched value for ["string", "integer"] union must not be a string');
        $this->assertIsNotInt($sentCode, 'Mismatched value for ["string", "integer"] union must not be an integer');
    }

    public function test_regression_error_status_code_returns_null_and_skips_when_no_4xx_declared(): void
    {
        $endpointNo4xx = [
            'method' => 'POST',
            'path' => '/notify',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['msg'],
                            'properties' => [
                                'msg' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
            ],
        ];

        $status = $this->generator->getErrorStatusCode($endpointNo4xx);
        $this->assertNull($status, 'Endpoint without 4xx or default responses should have null error status code');

        // Missing required and type mismatch should skip when no 4xx oracle exists
        $missing = $this->generator->generateMissingRequired($endpointNo4xx);
        $this->assertEmpty($missing, 'Should skip missing required when no 4xx error oracle is declared in spec');

        $typeMismatch = $this->generator->generateTypeMismatch($endpointNo4xx);
        $this->assertEmpty($typeMismatch, 'Should skip type mismatch when no 4xx error oracle is declared in spec');
    }

    public function test_regression_boundary_cases_generate_unique_data_across_cases(): void
    {
        $endpointWithMultipleBoundaries = [
            'method' => 'POST',
            'path' => '/categories',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name', 'slug'],
                            'properties' => [
                                'name' => [
                                    'type' => 'string',
                                    'minLength' => 2,
                                    'maxLength' => 50,
                                ],
                                'slug' => [
                                    'type' => 'string',
                                    'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
                                ],
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

        $cases = $this->generator->generateBoundary($endpointWithMultipleBoundaries);
        $this->assertNotEmpty($cases);

        // Filter valid cases expecting 201
        $valid201Cases = array_filter($cases, fn ($c) => ($c['steps'][0]['assertions'][0]['equals'] ?? null) === 201);
        $this->assertGreaterThanOrEqual(2, count($valid201Cases), 'Should have multiple valid boundary cases');

        $slugs = [];
        foreach ($valid201Cases as $c) {
            $slug = $c['steps'][0]['request']['body']['slug'];
            $this->assertNotEmpty($slug);
            $slugs[] = $slug;
        }

        // Slugs across all valid boundary cases must be strictly distinct to avoid 409 DB collision
        $this->assertSame(
            count($slugs),
            count(array_unique($slugs)),
            'Valid boundary cases must use unique slug values across cases to avoid 409 DB collision. Found duplicates: '.json_encode($slugs)
        );
    }

    public function test_regression_query_parameter_union_type_mismatch_skips_when_serialization_cannot_preserve_name(): void
    {
        $endpointWithUnionQuery = [
            'method' => 'GET',
            'path' => '/search',
            'parameters' => [
                [
                    'name' => 'filter',
                    'in' => 'query',
                    'schema' => [
                        'type' => ['integer', 'string'],
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Validation error'],
            ],
        ];

        $cases = $this->generator->generateTypeMismatch($endpointWithUnionQuery);
        // Must skip type mismatch when allowedTypes includes string, because sending array produces filter[0],
        // dropping parameter name 'filter' on the HTTP server and returning 200 instead of 422.
        $this->assertEmpty($cases, 'Should skip type mismatch for union query param containing string');

        // But for an integer-only query param, type mismatch should generate and preserve exact parameter name after HTTP serialization
        $endpointWithIntQuery = [
            'method' => 'GET',
            'path' => '/search',
            'parameters' => [
                [
                    'name' => 'page',
                    'in' => 'query',
                    'schema' => [
                        'type' => 'integer',
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
                '422' => ['description' => 'Validation error'],
            ],
        ];

        $intCases = $this->generator->generateTypeMismatch($endpointWithIntQuery);
        $this->assertNotEmpty($intCases, 'Should generate type mismatch for integer query param');
        $sentPage = $intCases[0]['steps'][0]['request']['query']['page'];
        $this->assertSame('not_a_valid_integer', $sentPage);

        // Verify actual HTTP serialization preserves exact parameter name
        $serialized = http_build_query($intCases[0]['steps'][0]['request']['query']);
        $this->assertMatchesRegularExpression('/(?:^|&)page=not_a_valid_integer/', $serialized, 'Serialized HTTP query must preserve exact parameter name page');
        $this->assertStringNotContainsString('page[', $serialized, 'Serialized HTTP query must not contain bracketed array keys');
    }

    public function test_regression_email_with_format_and_max_length_respects_max_length_constraint(): void
    {
        $endpointWithShortEmail = [
            'method' => 'POST',
            'path' => '/register',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['email'],
                            'properties' => [
                                'email' => [
                                    'type' => 'string',
                                    'format' => 'email',
                                    'maxLength' => 20,
                                ],
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

        // Generate multiple times to test counter increments (e.g. counter in 100-200 range)
        for ($i = 0; $i < 15; $i++) {
            $cases = $this->generator->generateHappyPath($endpointWithShortEmail);
            $this->assertCount(1, $cases);
            $email = $cases[0]['steps'][0]['request']['body']['email'];

            $this->assertLessThanOrEqual(20, strlen($email), "Email '{$email}' must not exceed maxLength: 20");
            $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), "Email '{$email}' must be a valid email format");
        }
    }

    public function test_path_parameter_generates_value_matching_schema_constraints(): void
    {
        $endpointWithVariedPathParams = [
            'method' => 'GET',
            'path' => '/orgs/{org_uuid}/users/{status}/orders/{order_id}/items/{code}',
            'parameters' => [
                [
                    'name' => 'org_uuid',
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => 'string',
                        'format' => 'uuid',
                    ],
                ],
                [
                    'name' => 'status',
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => 'string',
                        'enum' => ['active', 'archived'],
                    ],
                ],
                [
                    'name' => 'order_id',
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => 'integer',
                        'minimum' => 50,
                    ],
                ],
                [
                    'name' => 'code',
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => 'string',
                        'pattern' => '^PROD-[0-9]{3}$',
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'OK'],
            ],
        ];

        $cases = $this->generator->generateHappyPath($endpointWithVariedPathParams);
        $this->assertCount(1, $cases);

        $pathParams = $cases[0]['steps'][0]['request']['path_params'];

        // 1. UUID path parameter
        $this->assertArrayHasKey('org_uuid', $pathParams);
        $this->assertNotSame(1, $pathParams['org_uuid']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $pathParams['org_uuid'],
            'UUID path parameter must strictly match UUID format'
        );

        // 2. Enum path parameter
        $this->assertArrayHasKey('status', $pathParams);
        $this->assertContains($pathParams['status'], ['active', 'archived']);

        // 3. Integer path parameter with minimum != 1
        $this->assertArrayHasKey('order_id', $pathParams);
        $this->assertNotSame(1, $pathParams['order_id'], 'Integer path parameter must respect minimum > 1');
        $this->assertGreaterThanOrEqual(50, $pathParams['order_id']);

        // 4. Pattern path parameter
        $this->assertArrayHasKey('code', $pathParams);
        $this->assertMatchesRegularExpression('/^PROD-[0-9]{3}$/', (string) $pathParams['code']);
    }

    public function test_happy_path_respects_array_min_items_and_unique_items_constraints(): void
    {
        $endpointWithArrayConstraints = [
            'method' => 'POST',
            'path' => '/articles',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['title', 'tags'],
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'tags' => [
                                    'type' => 'array',
                                    'minItems' => 3,
                                    'maxItems' => 5,
                                    'uniqueItems' => true,
                                    'items' => ['type' => 'string'],
                                ],
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

        $cases = $this->generator->generateHappyPath($endpointWithArrayConstraints);
        $this->assertCount(1, $cases);

        $body = $cases[0]['steps'][0]['request']['body'];
        $this->assertIsArray($body['tags']);
        $this->assertGreaterThanOrEqual(3, count($body['tags']), 'Array body must satisfy minItems: 3');
        $this->assertLessThanOrEqual(5, count($body['tags']), 'Array body must satisfy maxItems: 5');

        // uniqueItems
        $this->assertSame(
            count($body['tags']),
            count(array_unique($body['tags'])),
            'Array elements must be strictly unique when uniqueItems is true'
        );

        // Validate complete DSL
        $res = $this->validator->validate($cases[0]);
        $this->assertTrue($res->isValid, 'DSL with array constraints must be valid: '.implode('; ', $res->errors));
    }

    public function test_boundary_valid_string_satisfies_simultaneous_format_and_pattern_constraints(): void
    {
        $endpointWithEmailBoundary = [
            'method' => 'POST',
            'path' => '/contacts',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['email'],
                            'properties' => [
                                'email' => [
                                    'type' => 'string',
                                    'format' => 'email',
                                    'minLength' => 6,
                                    'maxLength' => 20,
                                ],
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

        $cases = $this->generator->generateBoundary($endpointWithEmailBoundary);
        $this->assertNotEmpty($cases);

        // Find boundary valid cases expecting 201
        $validCases = array_values(array_filter($cases, fn ($c) => ($c['steps'][0]['assertions'][0]['equals'] ?? null) === 201));
        $this->assertGreaterThanOrEqual(2, count($validCases), 'Should generate minLength and maxLength valid boundary cases');

        $minCase = null;
        $maxCase = null;
        foreach ($validCases as $c) {
            if (str_contains($c['title'], 'minLength (6)')) {
                $minCase = $c;
            } elseif (str_contains($c['title'], 'maxLength (20)')) {
                $maxCase = $c;
            }
        }

        $this->assertNotNull($minCase, 'Must have boundary valid case at minLength 6');
        $minEmail = $minCase['steps'][0]['request']['body']['email'];
        $this->assertSame(6, strlen($minEmail), "minLength boundary value must have exact length 6: '{$minEmail}'");
        $this->assertNotFalse(filter_var($minEmail, FILTER_VALIDATE_EMAIL), "minLength boundary value must be a valid email: '{$minEmail}'");

        $this->assertNotNull($maxCase, 'Must have boundary valid case at maxLength 20');
        $maxEmail = $maxCase['steps'][0]['request']['body']['email'];
        $this->assertSame(20, strlen($maxEmail), "maxLength boundary value must have exact length 20: '{$maxEmail}'");
        $this->assertNotFalse(filter_var($maxEmail, FILTER_VALIDATE_EMAIL), "maxLength boundary value must be a valid email: '{$maxEmail}'");
    }

    public function test_boundary_valid_skips_success_oracle_when_constraints_are_impossible(): void
    {
        // Email requires at least 6 characters (e.g. u@a.co). Here maxLength: 4 makes a valid email impossible.
        $endpointImpossible = [
            'method' => 'POST',
            'path' => '/contacts',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['email'],
                            'properties' => [
                                'email' => [
                                    'type' => 'string',
                                    'format' => 'email',
                                    'minLength' => 2,
                                    'maxLength' => 4,
                                ],
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

        $cases = $this->generator->generateBoundary($endpointImpossible);
        $validCases = array_filter($cases, fn ($c) => ($c['steps'][0]['assertions'][0]['equals'] ?? null) === 201);

        // When a valid boundary value satisfying all constraints cannot be generated, MUST NOT attach success oracle
        $this->assertEmpty($validCases, 'Must NOT generate 201 success oracle when valid email cannot satisfy length 2 or 4');
    }

    public function test_regression_unique_items_preserves_item_schema_for_enum_uuid_and_boolean(): void
    {
        // 1. Enum integers: {type: integer, enum: [5, 10]}
        $epEnum = [
            'method' => 'POST',
            'path' => '/enum-array',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['numbers'],
                            'properties' => [
                                'numbers' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'integer',
                                        'enum' => [5, 10],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
            ],
        ];
        $casesEnum = $this->generator->generateHappyPath($epEnum);
        $this->assertNotEmpty($casesEnum);
        $numbers = $casesEnum[0]['steps'][0]['request']['body']['numbers'];
        $this->assertCount(2, $numbers);
        $this->assertContains(5, $numbers);
        $this->assertContains(10, $numbers);
        foreach ($numbers as $n) {
            $this->assertContains($n, [5, 10], "Element {$n} must belong to enum [5, 10]");
        }

        // 2. UUID strings: {type: string, format: uuid}
        $epUuid = [
            'method' => 'POST',
            'path' => '/uuid-array',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['ids'],
                            'properties' => [
                                'ids' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'string',
                                        'format' => 'uuid',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
            ],
        ];
        $casesUuid = $this->generator->generateHappyPath($epUuid);
        $this->assertNotEmpty($casesUuid);
        $ids = $casesUuid[0]['steps'][0]['request']['body']['ids'];
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
        $uuidRegex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($uuidRegex, $ids[0]);
        $this->assertMatchesRegularExpression($uuidRegex, $ids[1]);

        // 3. Booleans: {type: boolean}
        $epBool = [
            'method' => 'POST',
            'path' => '/bool-array',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['flags'],
                            'properties' => [
                                'flags' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'boolean',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
            ],
        ];
        $casesBool = $this->generator->generateHappyPath($epBool);
        $this->assertNotEmpty($casesBool);
        $flags = $casesBool[0]['steps'][0]['request']['body']['flags'];
        $this->assertCount(2, $flags);
        $this->assertIsBool($flags[0]);
        $this->assertIsBool($flags[1]);
        $this->assertNotSame($flags[0], $flags[1]);
    }

    public function test_regression_exclusive_maximum_and_minimum_integer_decimal_and_negative_limits(): void
    {
        // 1. minimum: 3, exclusiveMaximum: 3.5 -> only integer 3 is valid
        $ep1 = [
            'method' => 'POST',
            'path' => '/int-decimal',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['count'],
                            'properties' => [
                                'count' => [
                                    'type' => 'integer',
                                    'minimum' => 3,
                                    'exclusiveMaximum' => 3.5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $cases1 = $this->generator->generateHappyPath($ep1);
        $this->assertNotEmpty($cases1);
        $this->assertSame(3, $cases1[0]['steps'][0]['request']['body']['count'], 'Integer with minimum 3 and exclusiveMaximum 3.5 must be 3');

        // 2. minimum: -5, exclusiveMaximum: -2.5 -> valid integers are -5, -4, -3
        $ep2 = [
            'method' => 'POST',
            'path' => '/int-negative',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['offset'],
                            'properties' => [
                                'offset' => [
                                    'type' => 'integer',
                                    'minimum' => -5,
                                    'exclusiveMaximum' => -2.5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $cases2 = $this->generator->generateHappyPath($ep2);
        $this->assertNotEmpty($cases2);
        $val2 = $cases2[0]['steps'][0]['request']['body']['offset'];
        $this->assertGreaterThanOrEqual(-5, $val2);
        $this->assertLessThan(-2.5, $val2);

        // 3. exclusiveMinimum: 2.2, maximum: 5 -> valid integers are 3, 4, 5
        $ep3 = [
            'method' => 'POST',
            'path' => '/int-exmin',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['val'],
                            'properties' => [
                                'val' => [
                                    'type' => 'integer',
                                    'exclusiveMinimum' => 2.2,
                                    'maximum' => 5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $cases3 = $this->generator->generateHappyPath($ep3);
        $this->assertNotEmpty($cases3);
        $val3 = $cases3[0]['steps'][0]['request']['body']['val'];
        $this->assertGreaterThan(2.2, $val3);
        $this->assertLessThanOrEqual(5, $val3);
    }

    public function test_regression_pattern_exact_character_classes_with_fixed_lengths_avoid_false_uuid_and_sha(): void
    {
        // 1. ^[A-Z]{64}$ -> must be 64 uppercase chars, NOT lowercase hex SHA-256
        $epUpper64 = [
            'method' => 'POST',
            'path' => '/upper-hash',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['sig'],
                            'properties' => [
                                'sig' => [
                                    'type' => 'string',
                                    'pattern' => '^[A-Z]{64}$',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $cases1 = $this->generator->generateHappyPath($epUpper64);
        $this->assertNotEmpty($cases1);
        $sig = $cases1[0]['steps'][0]['request']['body']['sig'];
        $this->assertSame(64, strlen($sig));
        $this->assertMatchesRegularExpression('/^[A-Z]{64}$/', $sig, 'Must match uppercase 64 chars pattern, not lowercase hex');

        // 2. ^[0-9]{36}$ -> must be 36 digits, NOT hyphenated UUID
        $epDigits36 = [
            'method' => 'POST',
            'path' => '/digits-36',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['code'],
                            'properties' => [
                                'code' => [
                                    'type' => 'string',
                                    'pattern' => '^[0-9]{36}$',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $cases2 = $this->generator->generateHappyPath($epDigits36);
        $this->assertNotEmpty($cases2);
        $code = $cases2[0]['steps'][0]['request']['body']['code'];
        $this->assertSame(36, strlen($code));
        $this->assertMatchesRegularExpression('/^[0-9]{36}$/', $code, 'Must match 36 digits pattern, not hyphenated UUID');
        $this->assertDoesNotMatchRegularExpression('/-/', $code);
    }

    public function test_regression_boundary_enum_checks_simultaneous_constraints_and_skips_invalid_boundaries(): void
    {
        $epEnumPattern = [
            'method' => 'POST',
            'path' => '/boundary-enum-pattern',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['status'],
                            'properties' => [
                                'status' => [
                                    'type' => 'string',
                                    'enum' => ['bad', 'VALID'],
                                    'pattern' => '^[A-Z]+$',
                                    'minLength' => 3,
                                    'maxLength' => 5,
                                ],
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

        $cases = $this->generator->generateBoundary($epEnumPattern);
        $this->assertNotEmpty($cases);

        // Find boundary valid cases expecting 201
        $validCases = array_values(array_filter($cases, fn ($c) => ($c['steps'][0]['assertions'][0]['equals'] ?? null) === 201));

        // At minLength (3): 'bad' fails pattern ^[A-Z]+$, so minLength (3) boundary MUST NOT be generated with 201
        foreach ($validCases as $c) {
            $this->assertStringNotContainsString('minLength (3)', $c['title'], 'Must not generate 201 boundary valid case at length 3 when enum candidate violates pattern');
        }

        // At maxLength (5): 'VALID' satisfies all constraints, so maxLength (5) should be generated
        $maxCase = null;
        foreach ($validCases as $c) {
            if (str_contains($c['title'], 'maxLength (5)')) {
                $maxCase = $c;
                break;
            }
        }
        $this->assertNotNull($maxCase, 'Must generate boundary valid case at maxLength 5 with VALID');
        $statusVal = $maxCase['steps'][0]['request']['body']['status'];
        $this->assertSame('VALID', $statusVal);
        $this->assertMatchesRegularExpression('/^[A-Z]+$/', $statusVal);
    }

    public function test_regression_unique_items_generates_distinct_elements_for_numbers_objects_and_pattern_strings(): void
    {
        // 1. Number in range 1-10 with uniqueItems: true, minItems: 2
        $epNumber = [
            'method' => 'POST',
            'path' => '/unique-numbers',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['rates'],
                            'properties' => [
                                'rates' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'number',
                                        'minimum' => 1,
                                        'maximum' => 10,
                                    ],
                                ],
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

        $casesNum = $this->generator->generateHappyPath($epNumber);
        $this->assertCount(1, $casesNum);
        $rates = $casesNum[0]['steps'][0]['request']['body']['rates'];
        $this->assertCount(2, $rates);
        $this->assertNotSame($rates[0], $rates[1], 'Array elements must be distinct numbers');
        $this->assertGreaterThanOrEqual(1, $rates[0]);
        $this->assertLessThanOrEqual(10, $rates[0]);
        $this->assertGreaterThanOrEqual(1, $rates[1]);
        $this->assertLessThanOrEqual(10, $rates[1]);

        // 2. Object with integer id in range 1-10 with uniqueItems: true, minItems: 2
        $epObject = [
            'method' => 'POST',
            'path' => '/unique-objects',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['items'],
                            'properties' => [
                                'items' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['id'],
                                        'properties' => [
                                            'id' => [
                                                'type' => 'integer',
                                                'minimum' => 1,
                                                'maximum' => 10,
                                            ],
                                        ],
                                    ],
                                ],
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

        $casesObj = $this->generator->generateHappyPath($epObject);
        $this->assertCount(1, $casesObj);
        $items = $casesObj[0]['steps'][0]['request']['body']['items'];
        $this->assertCount(2, $items);
        $this->assertNotSame(json_encode($items[0]), json_encode($items[1]), 'Object array elements must be distinct');
        $this->assertGreaterThanOrEqual(1, $items[0]['id']);
        $this->assertLessThanOrEqual(10, $items[0]['id']);
        $this->assertGreaterThanOrEqual(1, $items[1]['id']);
        $this->assertLessThanOrEqual(10, $items[1]['id']);

        // 3. String with pattern ^[A-Z]{2}$ with uniqueItems: true, minItems: 2
        $epPattern = [
            'method' => 'POST',
            'path' => '/unique-codes',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['codes'],
                            'properties' => [
                                'codes' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'string',
                                        'pattern' => '^[A-Z]{2}$',
                                    ],
                                ],
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

        $casesPat = $this->generator->generateHappyPath($epPattern);
        $this->assertCount(1, $casesPat);
        $codes = $casesPat[0]['steps'][0]['request']['body']['codes'];
        $this->assertCount(2, $codes);
        $this->assertNotSame($codes[0], $codes[1], 'Pattern string array elements must be distinct');
        $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $codes[0]);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $codes[1]);

        // 4. Impossible uniqueItems schema skips HappyPath case rather than emitting invalid payload
        $epImpossible = [
            'method' => 'POST',
            'path' => '/impossible-unique',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['flags'],
                            'properties' => [
                                'flags' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'uniqueItems' => true,
                                    'items' => [
                                        'type' => 'integer',
                                        'enum' => [42],
                                    ],
                                ],
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

        $casesImp = $this->generator->generateHappyPath($epImpossible);
        $this->assertEmpty($casesImp, 'Must NOT generate happy path case when uniqueItems constraint is impossible to satisfy');
    }

    public function test_regression_integer_bounds_intersection_and_decimal_limits(): void
    {
        // 1. minimum: 3.5, maximum: 4 -> integer must be 4
        $epDecMin = [
            'method' => 'POST',
            'path' => '/integer-decimal-bounds',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['count'],
                            'properties' => [
                                'count' => [
                                    'type' => 'integer',
                                    'minimum' => 3.5,
                                    'maximum' => 4,
                                ],
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

        $casesDec = $this->generator->generateHappyPath($epDecMin);
        $this->assertCount(1, $casesDec);
        $countVal = $casesDec[0]['steps'][0]['request']['body']['count'];
        $this->assertSame(4, $countVal, 'Integer with minimum: 3.5 and maximum: 4 must generate 4, not 3');

        // 2. minimum: 5, exclusiveMinimum: 0, maximum: 6 -> must intersect and satisfy minimum: 5
        $epIntersect = [
            'method' => 'POST',
            'path' => '/integer-intersect-bounds',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['level'],
                            'properties' => [
                                'level' => [
                                    'type' => 'integer',
                                    'minimum' => 5,
                                    'exclusiveMinimum' => 0,
                                    'maximum' => 6,
                                ],
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

        $casesInter = $this->generator->generateHappyPath($epIntersect);
        $this->assertCount(1, $casesInter);
        $levelVal = $casesInter[0]['steps'][0]['request']['body']['level'];
        $this->assertGreaterThanOrEqual(5, $levelVal, 'exclusiveMinimum: 0 must not overwrite minimum: 5');
        $this->assertLessThanOrEqual(6, $levelVal);
    }

    public function test_regression_date_and_date_time_format_validates_calendar_dates_and_filters_enum(): void
    {
        $epDateEnum = [
            'method' => 'POST',
            'path' => '/date-enum-validation',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['date_field'],
                            'properties' => [
                                'date_field' => [
                                    'type' => 'string',
                                    'format' => 'date',
                                    'enum' => ['2026-99-99', '2026-01-01'],
                                    'minLength' => 10,
                                    'maxLength' => 10,
                                ],
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

        // Boundary valid cases expecting 201 must select the valid calendar date '2026-01-01'
        $cases = $this->generator->generateBoundary($epDateEnum);
        $valid201Cases = array_values(array_filter($cases, fn ($c) => ($c['steps'][0]['assertions'][0]['equals'] ?? null) === 201));
        $this->assertNotEmpty($valid201Cases, 'Should generate boundary valid cases with valid date enum candidate');

        foreach ($valid201Cases as $c) {
            $dateVal = $c['steps'][0]['request']['body']['date_field'];
            $this->assertSame('2026-01-01', $dateVal, "Boundary valid must choose '2026-01-01' instead of invalid '2026-99-99'");
        }

        // Direct schema validation unit assertions
        $dateSchema = ['type' => 'string', 'format' => 'date'];
        $this->assertFalse($this->generator->isValidStringForSchema('2026-99-99', $dateSchema));
        $this->assertFalse($this->generator->isValidStringForSchema('2026-02-30', $dateSchema));
        $this->assertTrue($this->generator->isValidStringForSchema('2026-01-01', $dateSchema));

        $dateTimeSchema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertFalse($this->generator->isValidStringForSchema('2026-99-99T12:00:00Z', $dateTimeSchema));
        $this->assertFalse($this->generator->isValidStringForSchema('2026-02-30T12:00:00Z', $dateTimeSchema));
        $this->assertTrue($this->generator->isValidStringForSchema('2026-01-01T12:00:00Z', $dateTimeSchema));
    }
}
