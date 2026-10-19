<?php

namespace Tests\Unit\Services;

use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Enums\HttpMethod;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenApiParserTest extends TestCase
{
    private OpenApiParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new OpenApiParser;
    }

    private function fixture(string $filename): string
    {
        $path = dirname(__DIR__, 2).'/Fixtures/OpenApi/'.$filename;
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }

    public function test_parses_valid_openapi_3_0_json_and_resolves_references(): void
    {
        $json = $this->fixture('petstore-3.0.json');
        $result = $this->parser->parse($json, ApiSpecFormat::Json);

        $this->assertInstanceOf(ParsedOpenApi::class, $result);
        $this->assertTrue($result->isValid);
        $this->assertSame(ApiSpecFormat::Json, $result->format);
        $this->assertSame('3.0.3', $result->openapiVersion);
        $this->assertSame('Petstore Sample API', $result->title);
        $this->assertNotEmpty($result->normalized);
        $this->assertEmpty($result->errors);

        // Endpoints extracted: /pets (GET, POST), /pets/{id} (GET, DELETE) => 4 endpoints
        $this->assertCount(4, $result->endpoints);

        $listPets = collect($result->endpoints)->first(
            fn ($ep) => $ep->path === '/pets' && $ep->method === HttpMethod::Get
        );
        $this->assertNotNull($listPets);
        $this->assertSame('listPets', $listPets->operationId);
        $this->assertSame('List all pets', $listPets->summary);

        // Merged parameters: X-Request-Id (header) from path + limit (query) from operation
        $this->assertNotNull($listPets->parameters);
        $paramNames = array_column($listPets->parameters, 'name');
        $this->assertContains('X-Request-Id', $paramNames);
        $this->assertContains('limit', $paramNames);

        // Check $ref resolution in response:
        $this->assertArrayHasKey('200', $listPets->responses);
        /** @var array<string, mixed> $resp200 */
        $resp200 = (array) data_get($listPets->responses, '200', []);
        /** @var array<string, mixed> $contentMap */
        $contentMap = is_array($resp200['content'] ?? null) ? $resp200['content'] : [];
        /** @var array<string, mixed> $jsonContent */
        $jsonContent = is_array($contentMap['application/json'] ?? null) ? $contentMap['application/json'] : [];
        /** @var array<string, mixed> $schema */
        $schema = is_array($jsonContent['schema'] ?? null) ? $jsonContent['schema'] : [];
        /** @var array<string, mixed> $itemsSchema */
        $itemsSchema = is_array($schema['items'] ?? null) ? $schema['items'] : [];
        $this->assertSame('object', $itemsSchema['type'] ?? null);
        $this->assertArrayHasKey('properties', $itemsSchema);
        $this->assertArrayHasKey('id', $itemsSchema['properties']);

        // Check POST /pets requestBody
        $createPet = collect($result->endpoints)->first(
            fn ($ep) => $ep->path === '/pets' && $ep->method === HttpMethod::Post
        );
        $this->assertNotNull($createPet);
        $this->assertNotNull($createPet->requestBody);
        $reqSchema = $createPet->requestBody['content']['application/json']['schema'] ?? [];
        $this->assertSame('object', $reqSchema['type'] ?? null);
        $this->assertArrayHasKey('name', $reqSchema['properties']);
    }

    public function test_parses_valid_openapi_3_1_yaml(): void
    {
        $yaml = $this->fixture('simple-store-3.1.yaml');
        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertTrue($result->isValid);
        $this->assertSame(ApiSpecFormat::Yaml, $result->format);
        $this->assertSame('3.1.0', $result->openapiVersion);
        $this->assertSame('Simple Store API', $result->title);
        $this->assertEmpty($result->errors);

        // /products (GET, POST), /products/{productId} (PUT) => 3 endpoints
        $this->assertCount(3, $result->endpoints);

        $updateProduct = collect($result->endpoints)->first(
            fn ($ep) => $ep->path === '/products/{productId}' && $ep->method === HttpMethod::Put
        );
        $this->assertNotNull($updateProduct);
        $this->assertSame('updateProduct', $updateProduct->operationId);

        // Path parameter merged
        $this->assertNotNull($updateProduct->parameters);
        $this->assertSame('productId', $updateProduct->parameters[0]['name'] ?? null);
    }

    public function test_fails_on_broken_yaml_syntax(): void
    {
        $yaml = $this->fixture('invalid-syntax.yaml');
        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Lỗi cú pháp YAML', $result->errors[0]);
    }

    /**
     * QA S13-03: valid JSON whose root is a scalar used to report "Lỗi cú pháp JSON: No error".
     *
     * @return array<string, array{string}>
     */
    public static function jsonScalarDocuments(): array
    {
        return [
            'integer' => ['0'],
            'string' => ['"x"'],
            'overflowing number' => ['1e999'],
            'boolean' => ['true'],
            'null' => ['null'],
        ];
    }

    #[DataProvider('jsonScalarDocuments')]
    public function test_json_scalar_document_reports_object_required_not_syntax_error(string $content): void
    {
        $result = $this->parser->parse($content, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertSame([OpenApiParser::NOT_AN_OBJECT_MESSAGE], $result->errors);
        $this->assertStringNotContainsString('No error', implode(' ', $result->errors));
    }

    public function test_json_syntax_error_still_reports_decoder_message(): void
    {
        $result = $this->parser->parse('{"openapi": ', ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertStringStartsWith('Lỗi cú pháp JSON: ', $result->errors[0]);
        $this->assertStringNotContainsString('No error', $result->errors[0]);
    }

    public function test_fails_on_unsupported_swagger_2_0(): void
    {
        $json = $this->fixture('unsupported-swagger2.json');
        $result = $this->parser->parse($json, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Swagger', $result->errors[0]);
    }

    public function test_fails_on_unresolvable_reference(): void
    {
        $json = $this->fixture('unresolvable-ref.json');
        $result = $this->parser->parse($json, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('tham chiếu', $result->errors[0]);
        $this->assertStringContainsString('#/components/schemas/NonExistentSchema', $result->errors[0]);
        $this->assertStringNotContainsString('[internal-path]', $result->errors[0]);
    }

    public function test_fails_on_missing_paths_field(): void
    {
        $json = $this->fixture('missing-paths.json');
        $result = $this->parser->parse($json, ApiSpecFormat::Json);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('paths', $result->errors[0]);
    }

    public function test_fails_when_content_exceeds_5mb(): void
    {
        $oversized = str_repeat(' ', 5 * 1024 * 1024 + 10);
        $result = $this->parser->parse($oversized);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('5 MB', $result->errors[0]);
    }

    public function test_detects_format_correctly(): void
    {
        $this->assertSame(ApiSpecFormat::Json, $this->parser->detectFormat('  {"openapi": "3.0.0"}'));
        $this->assertSame(ApiSpecFormat::Yaml, $this->parser->detectFormat('openapi: 3.0.0'));
    }

    public function test_fails_on_file_external_reference(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "SSRF Attack Spec"
  version: "1.0.0"
paths:
  /leak:
    get:
      responses:
        "200":
          $ref: "file:///etc/passwd"
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('tham chiếu nội bộ', $result->errors[0]);
        $this->assertStringNotContainsString('file:///', $result->errors[0]);
    }

    public function test_fails_on_http_and_private_ip_external_reference(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "SSRF Attack Spec"
  version: "1.0.0"
paths:
  /ssrf:
    get:
      responses:
        "200":
          $ref: "http://127.0.0.1:8000/api/v1/health"
        "500":
          $ref: "http://169.254.169.254/latest/meta-data"
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('tham chiếu nội bộ', $result->errors[0]);
    }

    public function test_fails_on_relative_external_reference(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "Relative External Ref Spec"
  version: "1.0.0"
paths:
  /relative:
    get:
      responses:
        "200":
          $ref: "../schema.json"
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('tham chiếu nội bộ', $result->errors[0]);
    }

    public function test_global_bearer_security_serialized_correctly(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "Secure API"
  version: "1.0.0"
security:
  - bearerAuth: []
paths:
  /secure:
    get:
      responses:
        "200":
          description: "OK"
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertTrue($result->isValid);
        $endpoint = $result->endpoints[0];
        $this->assertSame([['bearerAuth' => []]], $endpoint->security);
    }

    public function test_operation_security_empty_overrides_global_security(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "Public Endpoint Override Spec"
  version: "1.0.0"
security:
  - bearerAuth: []
paths:
  /public:
    get:
      security: []
      responses:
        "200":
          description: "Public endpoint"
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertTrue($result->isValid);
        $endpoint = $result->endpoints[0];
        $this->assertSame([], $endpoint->security);
    }

    public function test_oauth2_scopes_preserved_in_security_serialization(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.3"
info:
  title: "OAuth API"
  version: "1.0.0"
paths:
  /profile:
    get:
      security:
        - oauth2:
            - "read:profile"
            - "write:profile"
      responses:
        "200":
          description: "User profile"
components:
  securitySchemes:
    oauth2:
      type: oauth2
      flows:
        implicit:
          authorizationUrl: "https://example.com/oauth/dialog"
          scopes:
            "read:profile": "Read your profile"
            "write:profile": "Modify your profile"
YAML;

        $result = $this->parser->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertTrue($result->isValid);
        $endpoint = $result->endpoints[0];
        $this->assertSame([
            [
                'oauth2' => [
                    'read:profile',
                    'write:profile',
                ],
            ],
        ], $endpoint->security);
    }

    public function test_sanitizer_masks_internal_paths_while_preserving_json_pointers(): void
    {
        $method = new \ReflectionMethod(OpenApiParser::class, 'sanitizeErrorMessage');

        $input = "Error resolving '#/components/schemas/User': failed at /var/www/internal/app/spec.json and C:\\laragon\\www\\app.php with pointer '/components/schemas/User'";
        /** @var string $sanitized */
        $sanitized = $method->invoke($this->parser, $input);

        $this->assertStringContainsString('#/components/schemas/User', $sanitized);
        $this->assertStringContainsString('/components/schemas/User', $sanitized);
        $this->assertStringNotContainsString('/var/www/internal', $sanitized);
        $this->assertStringNotContainsString('C:\\laragon', $sanitized);
        $this->assertStringContainsString('[internal-path]', $sanitized);
    }

    public function test_sanitizer_masks_paths_with_various_delimiters_and_rejects_non_root_segments(): void
    {
        $method = new \ReflectionMethod(OpenApiParser::class, 'sanitizeErrorMessage');

        // Unix path preceded by brackets '['
        $resBracket = $method->invoke($this->parser, '[/var/www/internal/spec.json]');
        $this->assertSame('[[internal-path]]', $resBracket);

        // Unix path preceded by '='
        $resEqual = $method->invoke($this->parser, 'file=/var/www/internal/spec.json');
        $this->assertSame('file=[internal-path]', $resEqual);

        // Segment name prefix overlap without boundary (/components_backup, /paths_private) must be masked
        $resCompBackup = $method->invoke($this->parser, '/components_backup/private/spec.json');
        $this->assertSame('[internal-path]', $resCompBackup);

        $resPathsPrivate = $method->invoke($this->parser, '/paths_private/private/spec.json');
        $this->assertSame('[internal-path]', $resPathsPrivate);

        // Valid OpenAPI root JSON Pointers must be preserved
        $resInfoTitle = $method->invoke($this->parser, "Cannot resolve pointer '/info/title'");
        $this->assertSame("Cannot resolve pointer '/info/title'", $resInfoTitle);

        $resHashInfoTitle = $method->invoke($this->parser, "Cannot resolve '#/info/title'");
        $this->assertSame("Cannot resolve '#/info/title'", $resHashInfoTitle);

        $resCompSchemas = $method->invoke($this->parser, "pointer '/components/schemas/Pet'");
        $this->assertSame("pointer '/components/schemas/Pet'", $resCompSchemas);

        // Windows paths with delimiters
        $resWinBracket = $method->invoke($this->parser, '[C:\\laragon\\www\\spec.json]');
        $this->assertSame('[[internal-path]]', $resWinBracket);

        $resWinEqual = $method->invoke($this->parser, 'path=C:\\laragon\\www\\spec.json');
        $this->assertSame('path=[internal-path]', $resWinEqual);
    }
}
