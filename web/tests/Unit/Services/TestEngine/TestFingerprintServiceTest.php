<?php

namespace Tests\Unit\Services\TestEngine;

use App\Services\TestEngine\TestFingerprintService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TestFingerprintServiceTest extends TestCase
{
    protected TestFingerprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestFingerprintService;
    }

    public function test_calculate_returns_valid_64_char_hex_hash(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Get user profile',
            'endpoint' => ['method' => 'GET', 'path' => '/users/me'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Fetch profile',
                    'request' => ['method' => 'GET', 'path' => '/users/me'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $fingerprint = $this->service->calculate($dsl);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        $this->assertTrue($this->service->isValidFingerprint($fingerprint));
    }

    public function test_two_cases_with_different_titles_produce_identical_fingerprint(): void
    {
        $base = [
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'GET', 'path' => '/items/1'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Get item',
                    'request' => ['method' => 'GET', 'path' => '/items/1'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $case1 = array_merge($base, ['title' => 'Title 1: Retrieve item by ID']);
        $case2 = array_merge($base, ['title' => 'Title 2: Fetch single item']);

        $hash1 = $this->service->calculate($case1);
        $hash2 = $this->service->calculate($case2);

        $this->assertSame($hash1, $hash2, 'Cases differing only in title must produce the same fingerprint.');
    }

    public function test_two_cases_with_different_descriptions_produce_identical_fingerprint(): void
    {
        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'Check health',
            'description' => 'First description explaining check',
            'endpoint' => ['method' => 'GET', 'path' => '/health'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'ping',
                    'request' => ['method' => 'GET', 'path' => '/health'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $dsl2 = $dsl1;
        $dsl2['description'] = 'Completely different description';

        $this->assertSame($this->service->calculate($dsl1), $this->service->calculate($dsl2));
    }

    public function test_two_cases_with_different_tags_produce_identical_fingerprint(): void
    {
        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'List products',
            'tags' => ['catalog', 'v1', 'fast'],
            'endpoint' => ['method' => 'GET', 'path' => '/products'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'list',
                    'request' => ['method' => 'GET', 'path' => '/products'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $dsl2 = $dsl1;
        $dsl2['tags'] = ['frontend', 'smoke'];

        $this->assertSame($this->service->calculate($dsl1), $this->service->calculate($dsl2));
    }

    public function test_two_cases_with_different_key_ordering_produce_identical_fingerprint(): void
    {
        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'Post item',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => ['price' => 10, 'name' => 'Widget'],
                    ],
                    'assertions' => [
                        ['equals' => 201, 'type' => 'status'],
                    ],
                ],
            ],
        ];

        $dsl2 = [
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'title' => 'Post item',
            'dsl_version' => '1.0',
            'endpoint' => ['path' => '/items', 'method' => 'POST'],
            'steps' => [
                [
                    'name' => 'create',
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                    ],
                    'request' => [
                        'body' => ['name' => 'Widget', 'price' => 10],
                        'path' => '/items',
                        'method' => 'POST',
                    ],
                ],
            ],
        ];

        $this->assertSame($this->service->calculate($dsl1), $this->service->calculate($dsl2));
    }

    public function test_cases_with_different_methods_produce_different_fingerprints(): void
    {
        $getDsl = [
            'dsl_version' => '1.0',
            'title' => 'Item test',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'op',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $postDsl = $getDsl;
        $postDsl['endpoint'] = ['method' => 'POST', 'path' => '/items'];
        $postDsl['steps'][0]['request']['method'] = 'POST';

        $this->assertNotSame($this->service->calculate($getDsl), $this->service->calculate($postDsl));
    }

    public function test_cases_with_different_paths_produce_different_fingerprints(): void
    {
        $dslA = [
            'dsl_version' => '1.0',
            'title' => 'Path A',
            'endpoint' => ['method' => 'GET', 'path' => '/items/1'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'op',
                    'request' => ['method' => 'GET', 'path' => '/items/1'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $dslB = $dslA;
        $dslB['endpoint']['path'] = '/items/2';
        $dslB['steps'][0]['request']['path'] = '/items/2';

        $this->assertNotSame($this->service->calculate($dslA), $this->service->calculate($dslB));
    }

    public function test_string_json_input_produces_same_fingerprint_as_array(): void
    {
        $array = [
            'dsl_version' => '1.0',
            'title' => 'String test',
            'endpoint' => ['method' => 'GET', 'path' => '/test'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step',
                    'request' => ['method' => 'GET', 'path' => '/test'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $json = json_encode($array, JSON_THROW_ON_ERROR);

        $this->assertSame($this->service->calculate($array), $this->service->calculate($json));
    }

    public function test_real_fixture_files_compute_stable_fingerprints(): void
    {
        $fixturePath = dirname(__DIR__, 5).'/docs/contracts/examples/valid-create-item-happy-path.json';
        $this->assertFileExists($fixturePath);

        $fixtureContent = file_get_contents($fixturePath);
        $this->assertIsString($fixtureContent);

        $hash = $this->service->calculate($fixtureContent);
        $this->assertTrue($this->service->isValidFingerprint($hash));

        // Re-reading and hashing again gives identical hash
        $hashAgain = $this->service->calculate(json_decode($fixtureContent, true));
        $this->assertSame($hash, $hashAgain);
    }

    public function test_nested_payload_fields_named_title_or_description_are_preserved_and_affect_fingerprint(): void
    {
        $base = [
            'dsl_version' => '1.0',
            'title' => 'Outer Test Title',
            'endpoint' => ['method' => 'POST', 'path' => '/articles'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create article',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/articles',
                        'body' => [
                            'title' => 'Article One',
                            'description' => 'First description',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $differentBody = $base;
        $differentBody['steps'][0]['request']['body']['title'] = 'Article Two';

        $this->assertNotSame(
            $this->service->calculate($base),
            $this->service->calculate($differentBody),
            'Different inner payload titles must not be stripped and must yield different fingerprints.'
        );
    }

    public function test_wrapper_record_with_dsl_key_computes_same_fingerprint_as_inner_dsl(): void
    {
        $innerDsl = [
            'dsl_version' => '1.0',
            'title' => 'Original title',
            'endpoint' => ['method' => 'GET', 'path' => '/users'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step',
                    'request' => ['method' => 'GET', 'path' => '/users'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $wrapped = [
            'id' => 123,
            'title' => 'Different wrapper title',
            'dsl' => $innerDsl,
        ];

        $this->assertSame($this->service->calculate($innerDsl), $this->service->calculate($wrapped));
    }

    public function test_empty_object_and_empty_array_produce_different_fingerprints(): void
    {
        $jsonWithEmptyObj = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Empty object body',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step',
                    'request' => ['method' => 'POST', 'path' => '/items', 'body' => new \stdClass],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $jsonWithEmptyArr = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Empty array body',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step',
                    'request' => ['method' => 'POST', 'path' => '/items', 'body' => []],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $canonicalObj = $this->service->toCanonicalJson($jsonWithEmptyObj);
        $canonicalArr = $this->service->toCanonicalJson($jsonWithEmptyArr);

        $this->assertStringContainsString('"body":{}', $canonicalObj);
        $this->assertStringContainsString('"body":[]', $canonicalArr);
        $this->assertNotSame($canonicalObj, $canonicalArr);
        $this->assertNotSame($this->service->calculate($jsonWithEmptyObj), $this->service->calculate($jsonWithEmptyArr));
    }

    public function test_rfc_number_vectors_serialize_according_to_ecmascript(): void
    {
        $this->assertSame('1e+30', $this->service->formatNumber(1e30));
        $this->assertSame('-1e+30', $this->service->formatNumber(-1e30));
        $this->assertSame('1.5e+30', $this->service->formatNumber(1.5e30));
        $this->assertSame('0', $this->service->formatNumber(-0.0));
        $this->assertSame('0', $this->service->formatNumber(0.0));
        $this->assertSame('100', $this->service->formatNumber(100.0));
        $this->assertSame('123.456', $this->service->formatNumber(123.456));
        $this->assertSame('0.000001', $this->service->formatNumber(0.000001));
        $this->assertSame('1e-7', $this->service->formatNumber(1e-7));

        // In DSL context
        $dsl = [
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'POST', 'path' => '/nums'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'numbers',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/nums',
                        'body' => [
                            'large' => 1e30,
                            'neg_zero' => -0.0,
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $canonical = $this->service->toCanonicalJson($dsl);
        $this->assertStringContainsString('"large":1e+30', $canonical);
        $this->assertStringContainsString('"neg_zero":0', $canonical);
    }

    public function test_unicode_property_ordering_follows_utf16_code_units(): void
    {
        // Astral character U+10000 (UTF-16 high surrogate 0xD800) must sort before U+FFFF (BMP 0xFFFF)
        $dsl = [
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'POST', 'path' => '/unicode'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'unicode sort',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/unicode',
                        'body' => (object) [
                            "\u{FFFF}" => 'bmp_last',
                            "\u{10000}" => 'astral_first',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $canonical = $this->service->toCanonicalJson($dsl);

        // Astral character comes first in UTF-16 code unit ordering
        $posAstral = strpos($canonical, "\u{10000}");
        $posBmp = strpos($canonical, "\u{FFFF}");

        $this->assertNotFalse($posAstral);
        $this->assertNotFalse($posBmp);
        $this->assertLessThan($posBmp, $posAstral, 'Astral plane key must sort before BMP key in UTF-16 code unit order.');
    }

    public function test_fixed_canonical_json_and_sha256_output(): void
    {
        $dsl = [
            'title' => 'Ignored title',
            'description' => 'Ignored description',
            'tags' => ['ignored', 'tags'],
            'endpoint' => ['path' => '/items', 'method' => 'GET'],
            'dsl_version' => '1.0',
            'source' => 'RULE',
            'technique' => 'HAPPY_PATH',
            'steps' => [
                [
                    'assertions' => [['equals' => 200, 'type' => 'status']],
                    'name' => 'get items',
                    'request' => ['headers' => ['Accept' => 'application/json'], 'method' => 'GET', 'path' => '/items'],
                ],
            ],
        ];

        $expectedJson = '{"dsl_version":"1.0","endpoint":{"method":"GET","path":"/items"},"source":"RULE","steps":[{"assertions":[{"equals":200,"type":"status"}],"name":"get items","request":{"headers":{"Accept":"application/json"},"method":"GET","path":"/items"}}],"technique":"HAPPY_PATH"}';
        $expectedSha256 = hash('sha256', $expectedJson);

        $this->assertSame($expectedJson, $this->service->toCanonicalJson($dsl));
        $this->assertSame($expectedSha256, $this->service->calculate($dsl));
    }

    public function test_shared_fixture_file_matches_expected_canonical_json_and_sha256(): void
    {
        $fixturePath = dirname(__DIR__, 5).'/docs/contracts/examples/canonical-test-vector.json';
        $this->assertFileExists($fixturePath);

        $content = file_get_contents($fixturePath);
        $this->assertIsString($content);

        $vector = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

        // Input as object from fixture
        $canonicalJson = $this->service->toCanonicalJson($vector->dsl);
        $this->assertSame($vector->expected_canonical_json, $canonicalJson);

        $hash = $this->service->calculate($vector->dsl);
        $this->assertSame($vector->expected_sha256, $hash);
    }

    public function test_empty_dsl_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate([]);
    }

    public function test_malformed_json_string_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate('{malformed: json');
    }

    public function test_full_rfc_8785_appendix_b_vectors(): void
    {
        $vectors = [
            '0000000000000000' => '0',
            '8000000000000000' => '0',
            '0000000000000001' => '5e-324',
            '8000000000000001' => '-5e-324',
            '7fefffffffffffff' => '1.7976931348623157e+308',
            'ffefffffffffffff' => '-1.7976931348623157e+308',
            '4340000000000000' => '9007199254740992',
            'c340000000000000' => '-9007199254740992',
            '4430000000000000' => '295147905179352830000',
            '44b52d02c7e14af5' => '9.999999999999997e+22',
            '44b52d02c7e14af6' => '1e+23',
            '44b52d02c7e14af7' => '1.0000000000000001e+23',
            '444b1ae4d6e2ef4e' => '999999999999999700000',
            '444b1ae4d6e2ef4f' => '999999999999999900000',
            '444b1ae4d6e2ef50' => '1e+21',
            '3eb0c6f7a0b5ed8c' => '9.999999999999997e-7',
            '3eb0c6f7a0b5ed8d' => '0.000001',
            '41b3de4355555553' => '333333333.3333332',
            '41b3de4355555554' => '333333333.33333325',
            '41b3de4355555555' => '333333333.3333333',
            '41b3de4355555556' => '333333333.3333334',
            '41b3de4355555557' => '333333333.33333343',
            'becbf647612f3696' => '-0.0000033333333333333333',
            '43143ff3c1cb0959' => '1424953923781206.2',
        ];

        foreach ($vectors as $hex => $expected) {
            $bin = hex2bin((string) $hex);
            $this->assertIsString($bin);
            $unpacked = unpack('d', strrev($bin));
            $this->assertIsArray($unpacked);
            $double = $unpacked[1];
            $this->assertSame($expected, $this->service->formatNumber($double), "Failed formatting RFC 8785 vector: $hex ($expected)");
        }
    }

    public function test_nan_and_infinity_throw_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->formatNumber(NAN);
    }

    public function test_infinity_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->formatNumber(INF);
    }

    public function test_string_with_u2028_is_serialized_unescaped(): void
    {
        $u2028 = mb_chr(0x2028);
        $input = "line1{$u2028}line2";

        $serialized = $this->service->serializeString($input);
        $this->assertSame("\"line1{$u2028}line2\"", $serialized);
        $this->assertStringNotContainsString('\u2028', $serialized);

        $dsl = [
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'GET', 'path' => '/u2028'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => "step{$u2028}name",
                    'request' => ['method' => 'GET', 'path' => '/u2028'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $canonical = $this->service->toCanonicalJson($dsl);
        $this->assertStringContainsString("step{$u2028}name", $canonical);
        $this->assertStringNotContainsString('\u2028', $canonical);
    }

    public function test_root_array_is_canonicalized_preserving_order_and_elements(): void
    {
        $json = '[{"z":1,"a":2},{"d":4,"c":3}]';
        $canonical = $this->service->toCanonicalJson($json);

        $this->assertSame('[{"a":2,"z":1},{"c":3,"d":4}]', $canonical);

        $primitives = ['second', 'first'];
        $canonicalPrimitives = $this->service->toCanonicalJson($primitives);
        $this->assertSame('["second","first"]', $canonicalPrimitives);
    }

    public function test_regression_rfc8785_ieee754_integer_precision(): void
    {
        $json9007199254740993 = '{"n":9007199254740993}';
        $json9007199254740992 = '{"n":9007199254740992}';

        $this->assertSame('{"n":9007199254740992}', $this->service->toCanonicalJson($json9007199254740993));
        $this->assertSame('{"n":9007199254740992}', $this->service->toCanonicalJson(['n' => 9007199254740993]));
        $this->assertSame(
            $this->service->calculate($json9007199254740993),
            $this->service->calculate($json9007199254740992)
        );
    }

    public function test_regression_numeric_key_object_does_not_collide_with_list(): void
    {
        $jsonObject = '{"0":"a"}';
        $jsonList = '["a"]';

        $canonicalObj = $this->service->toCanonicalJson($jsonObject);
        $canonicalList = $this->service->toCanonicalJson($jsonList);

        $this->assertSame('{"0":"a"}', $canonicalObj);
        $this->assertSame('["a"]', $canonicalList);
        $this->assertNotSame($canonicalObj, $canonicalList);
        $this->assertNotSame(
            $this->service->calculate($jsonObject),
            $this->service->calculate($jsonList)
        );

        $stdObj = (object) ['0' => 'a'];
        $phpList = ['a'];

        $this->assertSame('{"0":"a"}', $this->service->toCanonicalJson($stdObj));
        $this->assertSame('["a"]', $this->service->toCanonicalJson($phpList));
        $this->assertNotSame(
            $this->service->calculate($stdObj),
            $this->service->calculate($phpList)
        );
    }

    public function test_regression_malformed_json_in_wrapper_throws_invalid_argument_exception(): void
    {
        $arrayWrapper = ['id' => 1, 'dsl' => '{malformed json'];

        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate($arrayWrapper);
    }

    public function test_regression_malformed_json_in_object_wrapper_throws_invalid_argument_exception(): void
    {
        $objectWrapper = (object) ['id' => 1, 'dsl' => '{malformed json'];

        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate($objectWrapper);
    }
}
