<?php

namespace App\Services\OpenApi;

use App\Data\OpenApi\ParsedEndpoint;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Enums\HttpMethod;
use App\Support\OpenApiStructure;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\SpecObjectInterface;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Service to parse, validate, resolve $ref, and normalize OpenAPI 3.0/3.1 specs.
 *
 * Implements docs/DESIGN.md §2 D06 and §10 step 1.
 */
class OpenApiParser
{
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Upper bound on the number of nodes in the document once YAML aliases and
     * internal $ref are expanded. Serialization (normalized spec + endpoints)
     * materialises every expanded node, so alias bombs or $ref fan-out chains
     * of a few hundred bytes would otherwise exhaust memory (docs/DESIGN.md §9).
     */
    public const MAX_EXPANDED_NODES = 200_000;

    /** Maximum nesting depth of the expanded document (matches json_decode's default). */
    public const MAX_EXPANDED_DEPTH = 512;

    /** Error shown when a JSON document parses but its root is a scalar instead of an object. */
    public const NOT_AN_OBJECT_MESSAGE = 'Tài liệu đặc tả phải là một đối tượng JSON/YAML (object) có trường "openapi".';

    /** UTF-8 byte order mark that Windows editors put at the start of a saved file. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * The content without a leading UTF-8 BOM (S14-06). The BOM marks the encoding, it is not part
     * of the document: json_decode rejects it, so it is removed before parsing, and a spec is
     * stored and checksummed without it (CreateApiSpecAction), so one document saved with and
     * without a BOM has one checksum.
     */
    public static function withoutBom(string $content): string
    {
        return str_starts_with($content, self::UTF8_BOM) ? substr($content, strlen(self::UTF8_BOM)) : $content;
    }

    /**
     * api_specs.checksum of a document: sha256 of the content without a leading UTF-8 BOM, i.e. of
     * the raw_content CreateApiSpecAction stores. Callers that look a spec up by checksum (demo
     * seeding, experiments) use it too, so a BOM never makes them miss the stored version.
     */
    public static function checksum(string $content): string
    {
        return hash('sha256', self::withoutBom($content));
    }

    /**
     * Parse OpenAPI specification string in JSON or YAML format.
     */
    public function parse(string $content, ?ApiSpecFormat $format = null): ParsedOpenApi
    {
        $content = self::withoutBom($content);
        if (strlen($content) > self::MAX_BYTES) {
            $detectedFormat = $format ?? $this->detectFormat($content);

            return ParsedOpenApi::failed($detectedFormat, ['Nội dung đặc tả vượt quá giới hạn 5 MB.']);
        }

        $detectedFormat = $format ?? $this->detectFormat($content);

        return match ($detectedFormat) {
            ApiSpecFormat::Json => $this->parseJson($content),
            ApiSpecFormat::Yaml => $this->parseYaml($content),
        };
    }

    /**
     * Detect specification format based on trimmed content.
     */
    public function detectFormat(string $content): ApiSpecFormat
    {
        $trimmed = ltrim(self::withoutBom($content));
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return ApiSpecFormat::Json;
        }

        return ApiSpecFormat::Yaml;
    }

    /**
     * Parse and normalize JSON OpenAPI content.
     */
    protected function parseJson(string $content): ParsedOpenApi
    {
        $raw = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Vietnamese message (S13-03); the PHP json error text is English.
            return ParsedOpenApi::failed(
                ApiSpecFormat::Json,
                ['Lỗi cú pháp JSON: nội dung không phải JSON hợp lệ (kiểm tra dấu ngoặc, dấu phẩy và dấu nháy kép).']
            );
        }

        // Valid JSON scalars (0, "x", 1e999, true, null) decode without error but are not a
        // document: report the real problem instead of "Lỗi cú pháp JSON: No error".
        if (! is_array($raw)) {
            return ParsedOpenApi::failed(
                ApiSpecFormat::Json,
                [self::NOT_AN_OBJECT_MESSAGE]
            );
        }

        $precheck = $this->precheckStructure($raw, ApiSpecFormat::Json);
        if ($precheck !== null) {
            return $precheck;
        }

        try {
            $readerData = OpenApiStructure::decodeJson($content);
            if (! is_array($readerData)) {
                throw new \UnexpectedValueException('OpenAPI root must be an object.');
            }
            $spec = new OpenApi($readerData);
        } catch (Throwable $e) {
            return ParsedOpenApi::failed(
                ApiSpecFormat::Json,
                ['Lỗi đọc cấu trúc OpenAPI JSON: '.$e->getMessage()]
            );
        }

        return $this->processSpec($spec, ApiSpecFormat::Json);
    }

    /**
     * Vietnamese reason for a $ref the parser cannot resolve (S14-06): the cebe message is English
     * and names internal classes ("... to cebe\\openapi\\spec\\Schema Object: Failed to evaluate
     * pointer ..."). Only the reference itself is kept.
     */
    private function describeReferenceError(Throwable $e): string
    {
        if (preg_match("/Failed to resolve Reference '([^']{1,300})'/", $e->getMessage(), $match) === 1) {
            $reference = $this->sanitizeErrorMessage($match[1]);

            return "Không giải quyết được tham chiếu \$ref \"{$reference}\": thành phần này không tồn tại trong đặc tả hoặc trỏ ra ngoài tài liệu.";
        }

        return 'Không giải quyết được một tham chiếu $ref trong đặc tả: hãy kiểm tra mọi $ref đều trỏ tới thành phần có trong tài liệu (dạng #/components/...).';
    }

    /**
     * Vietnamese YAML syntax error with the line and snippet (S13-03): the Symfony parser message
     * ("Malformed inline YAML string at line 4") is English.
     */
    private static function describeYamlError(Throwable $e): string
    {
        if (! $e instanceof ParseException) {
            return 'Lỗi cú pháp YAML: không đọc được nội dung YAML.';
        }

        $line = $e->getParsedLine();
        $snippet = trim((string) $e->getSnippet());
        $where = $line > 0 ? " ở dòng {$line}" : '';
        $near = $snippet !== '' ? ' (gần: "'.mb_substr($snippet, 0, 80).'")' : '';

        // Yaml::parse runs without PARSE_CUSTOM_TAGS / PARSE_OBJECT: a tag such as !!python/object
        // is rejected, which is not an indentation problem (S14-06).
        // !php/object, !php/const and !php/enum (rejected by PARSE_EXCEPTION_ON_INVALID_TYPE) and
        // unsupported built-in tags are the same problem.
        $message = $e->getMessage();
        if (str_contains($message, 'Tags support is not enabled')
            || str_contains($message, 'Object support when parsing a YAML file has been disabled')
            || str_contains($message, 'could not be parsed as a constant')
            || str_contains($message, 'could not be parsed as an enum')
            || str_contains($message, 'unsupported built-in tag')
            || preg_match('/tag "[^"]*" is not implemented/', $message) === 1) {
            $tag = preg_match('~(?<![\w"\'])(!{1,2}[A-Za-z0-9_./:-]+)~u', $snippet, $tagMatch) === 1
                ? $tagMatch[1]
                : null;

            return self::customTagMessage($where, $near, $tag);
        }

        return "Lỗi cú pháp YAML{$where}{$near}: hãy kiểm tra thụt lề, dấu hai chấm và các chuỗi trong dấu nháy đã được đóng.";
    }

    /** Vietnamese message for a YAML tag the platform does not accept (S14-06). */
    private static function customTagMessage(string $where, string $near, ?string $tag): string
    {
        $named = $tag !== null && $tag !== '' ? ' '.mb_substr($tag, 0, 40) : '';

        return "YAML{$where}{$near} chứa thẻ tùy biến{$named} không được hỗ trợ (ví dụ !php/object, !!python/object): chỉ chấp nhận YAML thuần dữ liệu.";
    }

    /**
     * The first tagged value of a parsed YAML document. Symfony keeps a tagged block scalar
     * ("title: !php/object |" or "!!python/object |" followed by indented lines) as a TaggedValue even
     * without PARSE_CUSTOM_TAGS, instead of rejecting it like an inline tag: it must be reported, not
     * turned into an "Array" title (S14-06).
     */
    private static function firstTaggedValue(mixed $value): ?TaggedValue
    {
        if ($value instanceof TaggedValue) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = self::firstTaggedValue($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** Custom-tag message for a tagged value found after parsing, with the line that carries the tag. */
    private static function describeTaggedValue(TaggedValue $tagged, string $content): string
    {
        $tag = '!'.$tagged->getTag();
        $where = '';
        $near = '';
        foreach (preg_split('/\R/u', $content) ?: [] as $index => $line) {
            if (preg_match('~(?<![\w"\'])'.preg_quote($tag, '~').'(?![A-Za-z0-9_./:-])~u', $line) === 1) {
                $where = ' ở dòng '.($index + 1);
                $near = ' (gần: "'.mb_substr(trim($line), 0, 80).'")';
                break;
            }
        }

        return self::customTagMessage($where, $near, $tag);
    }

    /**
     * Vietnamese text for an OpenAPI structure error of the reader (S14-06). The reader reports
     * "[/info] Info is missing required property: title" in English: the common cases are
     * translated and the JSON pointer is kept so the user finds the place. Other reader texts keep
     * their wording behind the Vietnamese prefix.
     */
    private static function describeStructureError(string $error): string
    {
        $pointer = '';
        if (preg_match('/\A\[([^\]]*)\]\s*(.*)\z/su', $error, $match) === 1) {
            $pointer = $match[1];
            $error = $match[2];
        }
        $where = $pointer !== '' ? ' tại '.$pointer : '';

        if (preg_match('/\A(\w+) is missing required property: (.+)\z/su', $error, $match) === 1) {
            return "Lỗi cấu trúc OpenAPI{$where}: đối tượng {$match[1]} thiếu trường bắt buộc \"{$match[2]}\" (hoặc trường này rỗng).";
        }
        if (preg_match('/\A(\w+) is missing at least one of the following required properties: (.+)\z/su', $error, $match) === 1) {
            return "Lỗi cấu trúc OpenAPI{$where}: đối tượng {$match[1]} phải có ít nhất một trong các trường: {$match[2]}.";
        }
        if (preg_match("/\\A\\w*Parameter 'required' must be true for 'in': 'path'\\.?\\s*\\z/u", $error) === 1) {
            return "Lỗi cấu trúc OpenAPI{$where}: tham số nằm trong path phải khai báo required: true.";
        }
        if (preg_match('/\A\w*A Parameter Object MUST contain either a schema property, or a content property, but not both\.?\s*\z/u', $error) === 1) {
            return "Lỗi cấu trúc OpenAPI{$where}: tham số phải có đúng một trong hai trường schema hoặc content.";
        }
        // Other validation texts of the reader (review of S14-06 round 3): an invalid contact email or
        // URL, a components key, a security scheme, a header, a parameter style or content.
        $translated = match (true) {
            preg_match('/\A(\w+)::\$(\w+) does not seem to be a valid email address: (.*)\z/su', $error, $match) === 1 => "trường \"{$match[2]}\" của {$match[1]} không phải địa chỉ email hợp lệ: {$match[3]}.",
            preg_match('/\A(\w+)::\$(\w+) does not seem to be a valid URL: (.*)\z/su', $error, $match) === 1 => "trường \"{$match[2]}\" của {$match[1]} không phải URL hợp lệ: {$match[3]}.",
            preg_match("/\\AInvalid key '(.*)' used in Components Object for attribute '(\\w+)'/su", $error, $match) === 1 => "khóa \"{$match[1]}\" trong components.{$match[2]} chỉ được chứa chữ cái, chữ số và các ký tự . - _.",
            preg_match('/\AUnknown Security Scheme type: (.*)\z/su', $error, $match) === 1 => "kiểu security scheme \"{$match[1]}\" không hợp lệ (chỉ nhận apiKey, http, oauth2, openIdConnect).",
            preg_match("/\\AInvalid value for Security Scheme property 'in': (.*)\\z/su", $error, $match) === 1 => "security scheme apiKey có giá trị in \"{$match[1]}\" không hợp lệ (chỉ nhận query, header, cookie).",
            preg_match('/\A\w*A Header Object MUST contain either a schema property, or a content property, but not both\.?\s*\z/u', $error) === 1 => 'header phải có đúng một trong hai trường schema hoặc content.',
            preg_match("/\\A\\w*'(name|in)' must not be specified in Header Object\\.?\\s*\\z/u", $error, $match) === 1 => "header không được khai báo trường \"{$match[1]}\".",
            preg_match('/\A\w*A Parameter Object with Content property MUST have A SINGLE content type\.?\s*\z/u', $error) === 1 => 'tham số dùng content chỉ được khai báo đúng một kiểu nội dung (media type).',
            preg_match('/\A\w*A Parameter Object DOES NOT support this serialization style\.?\s*\z/u', $error) === 1 => 'tham số khai báo kiểu tuần tự hóa (style) không hợp lệ với vị trí in của nó.',
            preg_match('/\A\w*Link: operationId and operationRef are mutually exclusive\.?\s*\z/u', $error) === 1 => 'Link không được khai báo đồng thời operationId và operationRef.',
            default => null,
        };
        if ($translated !== null) {
            return "Lỗi cấu trúc OpenAPI{$where}: {$translated}";
        }

        return "Lỗi cấu trúc OpenAPI{$where}: {$error}";
    }

    /**
     * Parse and normalize YAML OpenAPI content safely.
     */
    protected function parseYaml(string $content): ParsedOpenApi
    {
        try {
            // Parse safely without object evaluation flags. PARSE_EXCEPTION_ON_INVALID_TYPE makes
            // !php/object, !php/const and !php/enum fail instead of silently becoming null, so the
            // tag is reported like any other custom tag (S14-06).
            $raw = Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (Throwable $e) {
            return ParsedOpenApi::failed(ApiSpecFormat::Yaml, [self::describeYamlError($e)]);
        }

        if (! is_array($raw)) {
            return ParsedOpenApi::failed(
                ApiSpecFormat::Yaml,
                ['Tài liệu YAML không chứa cấu trúc hợp lệ (yêu cầu dạng map/object).']
            );
        }

        $tagged = self::firstTaggedValue($raw);
        if ($tagged !== null) {
            return ParsedOpenApi::failed(ApiSpecFormat::Yaml, [self::describeTaggedValue($tagged, $content)]);
        }

        $precheck = $this->precheckStructure($raw, ApiSpecFormat::Yaml);
        if ($precheck !== null) {
            return $precheck;
        }

        try {
            $typedRaw = Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            $readerData = OpenApiStructure::normalize($typedRaw);
            if (! is_array($readerData)) {
                throw new \UnexpectedValueException('OpenAPI root must be an object.');
            }
            $spec = new OpenApi($readerData);
        } catch (Throwable $e) {
            return ParsedOpenApi::failed(
                ApiSpecFormat::Yaml,
                ['Lỗi đọc cấu trúc OpenAPI YAML: '.$e->getMessage()]
            );
        }

        return $this->processSpec($spec, ApiSpecFormat::Yaml);
    }

    /**
     * Precheck root fields before full reader parsing.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function precheckStructure(array $raw, ApiSpecFormat $format): ?ParsedOpenApi
    {
        if (isset($raw['swagger'])) {
            $swaggerVer = is_scalar($raw['swagger']) ? (string) $raw['swagger'] : '2.0';

            return ParsedOpenApi::failed(
                $format,
                ["Chỉ hỗ trợ đặc tả OpenAPI 3.0.x và 3.1.x. Phát hiện đặc tả Swagger {$swaggerVer} không tương thích."],
                $swaggerVer
            );
        }

        if (empty($raw['openapi']) || ! is_string($raw['openapi'])) {
            return ParsedOpenApi::failed(
                $format,
                ['Thiếu trường bắt buộc "openapi" trong tài liệu đặc tả.']
            );
        }

        $version = trim($raw['openapi']);
        if (! str_starts_with($version, '3.0') && ! str_starts_with($version, '3.1')) {
            return ParsedOpenApi::failed(
                $format,
                ["Chỉ hỗ trợ đặc tả OpenAPI 3.0.x và 3.1.x. Phiên bản hiện tại ({$version}) không được hỗ trợ."],
                $version
            );
        }

        if (empty($raw['info']) || ! is_array($raw['info'])) {
            return ParsedOpenApi::failed(
                $format,
                ['Thiếu trường bắt buộc "info" hoặc info không hợp lệ trong tài liệu.'],
                $version
            );
        }

        if (! array_key_exists('paths', $raw) || ! is_array($raw['paths'])) {
            return ParsedOpenApi::failed(
                $format,
                ['Thiếu trường bắt buộc "paths" hoặc paths không hợp lệ trong tài liệu.'],
                $version
            );
        }

        if ($this->exceedsExpansionBudget($raw)) {
            return ParsedOpenApi::failed(
                $format,
                ['Đặc tả quá lớn hoặc lồng quá sâu sau khi mở rộng alias YAML và tham chiếu $ref (tối đa '
                    .self::MAX_EXPANDED_NODES.' nút, độ sâu '.self::MAX_EXPANDED_DEPTH.'). Từ chối để tránh cạn kiệt bộ nhớ.'],
                $version
            );
        }

        $refErrors = $this->validateReferences($raw);
        if ($refErrors !== []) {
            return ParsedOpenApi::failed(
                $format,
                $refErrors,
                $version
            );
        }

        return null;
    }

    /**
     * Resolve references, extract normalized endpoints and build ParsedOpenApi.
     */
    protected function processSpec(OpenApi $spec, ApiSpecFormat $format): ParsedOpenApi
    {
        $version = (string) $spec->openapi;
        $title = (string) $spec->info->title !== '' ? (string) $spec->info->title : null;

        // Resolve $ref references using safe in-memory context (no file/network I/O)
        try {
            $context = new SafeReferenceContext($spec, 'memory://spec.'.$format->value);
            $context->throwException = true;
            $spec->setReferenceContext($context);
            $spec->resolveReferences();
        } catch (UnresolvableReferenceException $e) {
            return ParsedOpenApi::failed($format, [$this->describeReferenceError($e)], $version);
        } catch (Throwable $e) {
            // The library text is English and names internal classes: log it, show a reason only.
            try {
                Log::info('OpenAPI reference resolution failed', [
                    'exception' => $e::class,
                    'message' => $this->sanitizeErrorMessage($e->getMessage()),
                ]);
            } catch (Throwable) {
                // The parser also runs outside the framework (unit tests, tools): no logger there.
            }

            return ParsedOpenApi::failed($format, [$this->describeReferenceError($e)], $version);
        }

        // Validate basic schema rules
        $spec->validate();
        $specErrors = $spec->getErrors();
        if ($specErrors !== []) {
            $criticalErrors = [];
            foreach ($specErrors as $error) {
                // If paths or fundamental structure is invalid, treat as failure
                $criticalErrors[] = self::describeStructureError((string) $error);
            }

            return ParsedOpenApi::failed($format, $criticalErrors, $version);
        }

        // Normalize endpoints
        $endpoints = $this->extractEndpoints($spec);
        if ($endpoints === [] && ! empty($spec->paths)) {
            // Path entries existed but no valid operations were found
            return ParsedOpenApi::failed(
                $format,
                ['Không tìm thấy thao tác HTTP (operations) hợp lệ nào trong các đường dẫn đã khai báo.'],
                $version
            );
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->serializeData($spec->getSerializableData());

        return new ParsedOpenApi(
            isValid: true,
            format: $format,
            openapiVersion: $version,
            title: $title,
            normalized: $normalized,
            endpoints: $endpoints,
            errors: [],
        );
    }

    /**
     * Extract and normalize individual endpoints from OpenApi object.
     *
     * @return list<ParsedEndpoint>
     */
    protected function extractEndpoints(OpenApi $spec): array
    {
        $endpoints = [];
        /** @var mixed $specSecurity */
        $specSecurity = $spec->security;
        $globalSecurity = $specSecurity !== null && $specSecurity !== []
            ? $this->serializeData($specSecurity)
            : null;

        $methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

        foreach ($spec->paths as $rawPath => $pathItem) {
            $path = str_starts_with((string) $rawPath, '/') ? (string) $rawPath : '/'.$rawPath;

            // Extract path-level parameters
            $pathParameters = [];
            if (! empty($pathItem->parameters)) {
                foreach ($pathItem->parameters as $parameter) {
                    $paramObj = $parameter instanceof Reference ? $parameter->resolve() : $parameter;
                    if ($paramObj instanceof Parameter) {
                        $serialized = $this->serializeData($paramObj);
                        if (is_array($serialized) && isset($serialized['name'], $serialized['in'])) {
                            $key = $serialized['in'].':'.$serialized['name'];
                            $pathParameters[$key] = $serialized;
                        }
                    }
                }
            }

            foreach ($methods as $methodStr) {
                $operation = $pathItem->{$methodStr};
                if (! ($operation instanceof Operation)) {
                    continue;
                }

                $httpMethod = HttpMethod::from(strtoupper($methodStr));

                // Merge operation-level parameters with path-level parameters
                $mergedParameters = $pathParameters;
                if (! empty($operation->parameters)) {
                    foreach ($operation->parameters as $opParam) {
                        $paramObj = $opParam instanceof Reference ? $opParam->resolve() : $opParam;
                        if ($paramObj instanceof Parameter) {
                            $serialized = $this->serializeData($paramObj);
                            if (is_array($serialized) && isset($serialized['name'], $serialized['in'])) {
                                $key = $serialized['in'].':'.$serialized['name'];
                                $mergedParameters[$key] = $serialized;
                            }
                        }
                    }
                }

                /** @var list<array<string, mixed>>|null $finalParameters */
                $finalParameters = ! empty($mergedParameters) ? array_values($mergedParameters) : null;

                // Request body
                $requestBody = null;
                if ($operation->requestBody !== null) {
                    $rbObj = $operation->requestBody instanceof Reference
                        ? $operation->requestBody->resolve()
                        : $operation->requestBody;

                    $serializedRb = $this->serializeData($rbObj);
                    if (is_array($serializedRb)) {
                        /** @var array<string, mixed> $requestBody */
                        $requestBody = $serializedRb;
                    }
                }

                // Responses
                $responses = [];
                if (! empty($operation->responses)) {
                    foreach ($operation->responses as $statusCode => $respItem) {
                        $respObj = method_exists($respItem, 'resolve')
                            ? $respItem->resolve()
                            : $respItem;

                        $serializedResp = $this->serializeData($respObj);
                        $responses[(string) $statusCode] = is_array($serializedResp) ? $serializedResp : [];
                    }
                }

                // Security
                // An operation specifying security: [] explicitly overrides global security with unauthenticated.
                // An operation omitting security ($operation->security === null) inherits global security.
                $security = null;
                /** @var mixed $opSecurity */
                $opSecurity = $operation->security;
                if ($opSecurity !== null) {
                    $security = $this->serializeData($opSecurity);
                } elseif (! empty($globalSecurity)) {
                    $security = $globalSecurity;
                }

                /** @var list<array<string, mixed>>|null $finalSecurity */
                $finalSecurity = is_array($security) ? array_values($security) : null;

                $endpoints[] = new ParsedEndpoint(
                    method: $httpMethod,
                    path: $path,
                    operationId: (string) $operation->operationId !== '' ? (string) $operation->operationId : null,
                    summary: (string) $operation->summary !== '' ? (string) $operation->summary : null,
                    description: (string) $operation->description !== '' ? (string) $operation->description : null,
                    parameters: $finalParameters,
                    requestBody: $requestBody,
                    responses: $responses,
                    security: $finalSecurity,
                );
            }
        }

        return $endpoints;
    }

    /**
     * Safely serialize cebe spec object or array to normalized array representation,
     * preserving typed data literals (stdClass for objects in const/enum).
     */
    protected function serializeData(mixed $data): mixed
    {
        return OpenApiStructure::normalize($this->convertSpecData($data));
    }

    /**
     * Recursively convert SpecObjectInterface instances or objects to serializable structures,
     * while preserving data-bearing literals intact.
     */
    protected function convertSpecData(mixed $data): mixed
    {
        if ($data instanceof SpecObjectInterface || (is_object($data) && method_exists($data, 'getSerializableData'))) {
            $data = $data->getSerializableData();
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $item) {
                $result[$key] = $this->convertSpecData($item);
            }

            return $result;
        }

        if (is_object($data)) {
            $result = new \stdClass;
            foreach (get_object_vars($data) as $key => $value) {
                $result->{$key} = $this->convertSpecData($value);
            }

            return $result;
        }

        return $data;
    }

    /**
     * Count nodes of the fully expanded document (YAML aliases are already
     * expanded by the parser; internal '#/...' $ref are followed), stopping as
     * soon as the budget is exceeded so the check itself stays cheap.
     *
     * @param  array<mixed>  $raw
     */
    protected function exceedsExpansionBudget(array $raw, int $budget = self::MAX_EXPANDED_NODES): bool
    {
        $count = 0;

        return $this->countExpandedNodes($raw, $raw, [], $count, $budget, 0);
    }

    /**
     * @param  array<mixed>  $root
     * @param  array<string, true>  $refStack  pointers being expanded (cycle guard)
     */
    private function countExpandedNodes(mixed $node, array $root, array $refStack, int &$count, int $budget, int $depth): bool
    {
        // Over-deep expansion (e.g. a long $ref chain) is treated as over budget too.
        if (++$count > $budget || $depth > self::MAX_EXPANDED_DEPTH) {
            return true;
        }

        if (! is_array($node)) {
            return false;
        }

        $ref = $node['$ref'] ?? null;
        if (is_string($ref) && str_starts_with($ref, '#/') && ! isset($refStack[$ref])) {
            $target = $this->resolveLocalPointer($root, $ref);
            if ($target !== null
                && $this->countExpandedNodes($target, $root, $refStack + [$ref => true], $count, $budget, $depth + 1)) {
                return true;
            }
        }

        foreach ($node as $key => $child) {
            if ($key === '$ref') {
                continue;
            }
            if ($this->countExpandedNodes($child, $root, $refStack, $count, $budget, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a local JSON Pointer ('#/a/b') inside the raw document.
     *
     * @param  array<mixed>  $root
     */
    private function resolveLocalPointer(array $root, string $ref): mixed
    {
        $current = $root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Recursively find and validate all $ref occurrences.
     * Only local fragment references starting with '#/' are permitted.
     *
     * @return list<string> list of error messages, empty if all references are valid.
     */
    protected function validateReferences(mixed $data): array
    {
        $errors = [];

        if (! is_array($data)) {
            return $errors;
        }

        foreach ($data as $key => $value) {
            if ($key === '$ref') {
                if (! is_string($value) || ! str_starts_with($value, '#/')) {
                    $errors[] = "Chỉ chấp nhận tham chiếu nội bộ dạng '#/...'. Từ chối tham chiếu ngoài không hợp lệ.";
                }
            } elseif (is_array($value)) {
                $childErrors = $this->validateReferences($value);
                if ($childErrors !== []) {
                    $errors = array_merge($errors, $childErrors);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Strip internal paths, local filesystem directories, and file:// URIs from error messages.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Remove file:// URIs
        $message = preg_replace('#file://[a-zA-Z0-9_\-\.\\\\/]+#i', '[local-ref]', $message) ?? $message;

        // Remove Windows absolute paths (e.g., C:\laragon\... or C:/laragon/...)
        $message = preg_replace('#[a-zA-Z]:[\\\\/][a-zA-Z0-9_\-\.\\\\/]+#', '[internal-path]', $message) ?? $message;

        // Remove Unix absolute paths (e.g., /var/www/... or /etc/...) while preserving root JSON Pointers (/components/..., /paths/..., /info/..., #/...)
        $allowedRoots = 'components|paths|definitions|schemas|responses|parameters|securitySchemes|requestBodies|headers|info|servers|security|tags|externalDocs|webhooks';

        $message = preg_replace_callback(
            '@(?<![\w#])/(?:[a-zA-Z0-9_.~-]+/)+[a-zA-Z0-9_.~-]*@',
            function (array $matches) use ($allowedRoots): string {
                $path = $matches[0];
                if (preg_match('@^/(?:'.$allowedRoots.')(?:/|$)@', $path)) {
                    return $path;
                }

                return '[internal-path]';
            },
            $message
        ) ?? $message;

        return $message;
    }
}
