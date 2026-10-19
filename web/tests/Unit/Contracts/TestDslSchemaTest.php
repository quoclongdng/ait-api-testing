<?php

namespace Tests\Unit\Contracts;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestDslSchemaTest extends TestCase
{
    private Validator $validator;

    private object $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new Validator;
        $schemaPath = dirname(__DIR__, 4).'/docs/contracts/test-dsl.schema.json';
        $this->assertFileExists($schemaPath, "Schema file not found at {$schemaPath}");

        $raw = file_get_contents($schemaPath);
        $this->assertNotFalse($raw);

        $decoded = json_decode($raw);
        $this->assertIsObject($decoded);
        $this->schema = $decoded;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validExampleProvider(): array
    {
        $dir = dirname(__DIR__, 4).'/docs/contracts/examples';
        $files = glob($dir.'/valid-*.json') ?: [];

        $data = [];
        foreach ($files as $file) {
            $data[basename($file)] = [$file];
        }

        return $data;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidExampleProvider(): array
    {
        $dir = dirname(__DIR__, 4).'/docs/contracts/examples';
        $files = glob($dir.'/invalid-*.json') ?: [];

        $data = [];
        foreach ($files as $file) {
            $data[basename($file)] = [$file];
        }

        return $data;
    }

    #[DataProvider('validExampleProvider')]
    public function test_valid_examples_pass_schema(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content);

        $data = json_decode($content);
        $this->assertIsObject($data);

        $result = $this->validator->validate($data, $this->schema);
        $this->assertTrue(
            $result->isValid(),
            'Expected '.basename($filePath).' to be valid, but got errors: '.($result->error() ? $result->error()->message() : 'unknown error')
        );
    }

    #[DataProvider('invalidExampleProvider')]
    public function test_invalid_examples_fail_schema(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content);

        $data = json_decode($content);
        $this->assertIsObject($data);

        $result = $this->validator->validate($data, $this->schema);
        $this->assertFalse(
            $result->isValid(),
            'Expected '.basename($filePath).' to fail schema validation, but it passed.'
        );
    }
}
