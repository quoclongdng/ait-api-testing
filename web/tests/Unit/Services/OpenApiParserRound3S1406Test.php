<?php

namespace Tests\Unit\Services;

use App\Enums\ApiSpecFormat;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S14-06 live QA round 3: YAML !php/* tags are reported (never silently turned into null), reader
 * structure errors are Vietnamese, and a UTF-8 BOM before a JSON/YAML document is ignored.
 */
class OpenApiParserRound3S1406Test extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function phpTags(): array
    {
        return [
            'php object' => ["  title: !php/object 'O:8:\"stdClass\":0:{}'\n", '!php/object'],
            'php const' => ["  title: !php/const PHP_EOL\n", '!php/const'],
            'php enum' => ["  title: !php/enum Foo::Bar\n", '!php/enum'],
            'php object in a list' => ["  title: T\n  x-list:\n    - !php/object 'O:8:\"stdClass\":0:{}'\n", '!php/object'],
            'python object' => ["  title: !!python/object/apply:os.system [\"id\"]\n", '!!python/object/apply:os.system'],
        ];
    }

    #[DataProvider('phpTags')]
    public function test_yaml_tags_are_rejected_with_the_vietnamese_custom_tag_message(string $infoLines, string $tag): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n{$infoLines}  version: '1'\npaths: {}\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("chứa thẻ tùy biến {$tag} không được hỗ trợ", $result->errors[0]);
        foreach (['missing', 'Info', 'PARSE_', 'Object support'] as $leak) {
            $this->assertStringNotContainsString($leak, $result->errors[0]);
        }
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function taggedBlockScalars(): array
    {
        return [
            'php object block' => ["  title: !php/object |\n    O:8:\"stdClass\":0:{}\n", '!php/object', 3],
            'python object block' => ["  title: T\n  x-a: !!python/object |\n    x\n", '!!python/object', 4],
            'custom tag block in a list' => ["  title: T\n  x-list:\n    - !custom >\n      x\n", '!custom', 5],
        ];
    }

    #[DataProvider('taggedBlockScalars')]
    public function test_tagged_block_scalars_are_rejected_not_turned_into_data(string $infoLines, string $tag, int $line): void
    {
        // Symfony keeps "key: !tag |" block scalars as TaggedValue objects even without
        // PARSE_CUSTOM_TAGS; before the review fix the title silently became "Array".
        $yaml = "openapi: 3.0.3\ninfo:\n{$infoLines}  version: '1'\npaths:\n  /p:\n    get:\n      responses:\n        '200':\n          description: ok\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertCount(1, $result->errors);
        $this->assertStringStartsWith("YAML ở dòng {$line} (gần: ", $result->errors[0]);
        $this->assertStringContainsString("chứa thẻ tùy biến {$tag} không được hỗ trợ", $result->errors[0]);
    }

    public function test_plain_strings_that_look_like_tags_stay_data(): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  title: \"!php/object x\"\n  version: '1'\npaths:\n  /p:\n    get:\n      responses:\n        '200':\n          description: ok\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertTrue($result->isValid, implode('; ', $result->errors));
        $this->assertSame('!php/object x', $result->title);
    }

    public function test_reader_structure_errors_are_vietnamese_and_keep_the_location(): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  version: '1'\npaths: {}\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertSame(['Lỗi cấu trúc OpenAPI: đối tượng Info thiếu trường bắt buộc "title" (hoặc trường này rỗng).'], $result->errors);
    }

    public function test_path_parameter_without_required_is_described_in_vietnamese(): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  title: T\n  version: '1'\npaths:\n  /a/{id}:\n    get:\n      parameters:\n        - name: id\n          in: path\n          schema:\n            type: string\n      responses:\n        '200':\n          description: ok\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertSame([
            'Lỗi cấu trúc OpenAPI: đối tượng Parameter thiếu trường bắt buộc "required" (hoặc trường này rỗng).',
            'Lỗi cấu trúc OpenAPI: tham số nằm trong path phải khai báo required: true.',
        ], $result->errors);
    }

    public function test_other_reader_validation_texts_are_vietnamese(): void
    {
        $paths = "paths:\n  /p:\n    get:\n      parameters:\n        - name: q\n          in: query\n          style: weird\n          schema: {type: string}\n        - name: r\n          in: query\n          content:\n            application/json: {schema: {type: string}}\n            text/plain: {schema: {type: string}}\n      responses:\n        '200':\n          description: ok\n";
        $yaml = "openapi: 3.0.3\ninfo:\n  title: T\n  version: '1'\n  contact:\n    email: notanemail\n    url: 'not a url'\n{$paths}"
            ."components:\n  schemas:\n    'bad key!':\n      type: string\n  securitySchemes:\n    s:\n      type: magic\n    k:\n      type: apiKey\n      name: x\n      in: body\n";

        $result = (new OpenApiParser)->parse($yaml, ApiSpecFormat::Yaml);

        $this->assertFalse($result->isValid);
        $this->assertEqualsCanonicalizing([
            'Lỗi cấu trúc OpenAPI: trường "email" của Contact không phải địa chỉ email hợp lệ: notanemail.',
            'Lỗi cấu trúc OpenAPI: trường "url" của Contact không phải URL hợp lệ: not a url.',
            'Lỗi cấu trúc OpenAPI: tham số khai báo kiểu tuần tự hóa (style) không hợp lệ với vị trí in của nó.',
            'Lỗi cấu trúc OpenAPI: tham số dùng content chỉ được khai báo đúng một kiểu nội dung (media type).',
            'Lỗi cấu trúc OpenAPI: khóa "bad key!" trong components.schemas chỉ được chứa chữ cái, chữ số và các ký tự . - _.',
            'Lỗi cấu trúc OpenAPI: kiểu security scheme "magic" không hợp lệ (chỉ nhận apiKey, http, oauth2, openIdConnect).',
            'Lỗi cấu trúc OpenAPI: security scheme apiKey có giá trị in "body" không hợp lệ (chỉ nhận query, header, cookie).',
        ], $result->errors);
    }

    public function test_utf8_bom_before_json_and_yaml_is_ignored(): void
    {
        $json = '{"openapi":"3.0.3","info":{"title":"Bom","version":"1"},"paths":{"/p":{"get":{"responses":{"200":{"description":"ok"}}}}}}';
        $parser = new OpenApiParser;

        $this->assertSame(ApiSpecFormat::Json, $parser->detectFormat(OpenApiParser::UTF8_BOM.$json));
        $fromJson = $parser->parse(OpenApiParser::UTF8_BOM.$json, ApiSpecFormat::Json);
        $this->assertTrue($fromJson->isValid, implode('; ', $fromJson->errors));
        $this->assertSame('Bom', $fromJson->title);

        $yaml = "openapi: 3.0.3\ninfo:\n  title: Bom\n  version: '1'\npaths:\n  /p:\n    get:\n      responses:\n        '200':\n          description: ok\n";
        $fromYaml = $parser->parse(OpenApiParser::UTF8_BOM.$yaml);
        $this->assertTrue($fromYaml->isValid, implode('; ', $fromYaml->errors));

        $this->assertSame($json, OpenApiParser::withoutBom(OpenApiParser::UTF8_BOM.$json));
        $this->assertSame($json, OpenApiParser::withoutBom($json));
        $this->assertSame(hash('sha256', $json), OpenApiParser::checksum(OpenApiParser::UTF8_BOM.$json));
        $this->assertSame(hash('sha256', $json), OpenApiParser::checksum($json));
    }
}
