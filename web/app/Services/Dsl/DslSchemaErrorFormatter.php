<?php

namespace App\Services\Dsl;

use Opis\JsonSchema\Errors\ValidationError;

/**
 * Turns an opis/json-schema error tree for a Test DSL document into short Vietnamese messages with
 * the location of the problem (S13-03), e.g. "steps[0].assertions[2]: thiếu trường bắt buộc op".
 * The raw opis text ("The data should match exactly one schema") gives a user nothing to fix.
 *
 * For a whole Test DSL document (forDslDocument) a location inside a step names the step like the
 * semantic errors of DslValidator, 1-based (S14-06): "Bước 1 ('Execute GET /items'), assertion #3:
 * thiếu trường bắt buộc op" instead of the 0-based JSON path "steps[0].assertions[2]". The step
 * name is quoted exactly as written in the DSL JSON the user edits (S14-06 live QA r7), not the
 * StepLabel display form, so it can be found in the editor; ai-service quotes it the same way.
 *
 * For oneOf/anyOf only one branch is reported: the branch whose "type" const equals the data's
 * "type" (an assertion {"type": "json_path"} is checked as a json_path assertion), otherwise the
 * branch with the fewest problems, so the user gets the problem of the shape they meant instead of
 * one line per assertion type of the schema. An unknown "type" is reported with the allowed values.
 */
final class DslSchemaErrorFormatter
{
    /**
     * @param  string  $rootLabel  shown when the problem is at the document root
     * @param  string  $pathPrefix  prepended to locations, e.g. '$' for a response body ("$.items[0]")
     * @param  object|null  $dslDocument  the Test DSL being validated: step locations read "Bước N"
     */
    public function __construct(
        private readonly string $rootLabel = 'Gốc DSL',
        private readonly string $pathPrefix = '',
        private readonly ?object $dslDocument = null,
    ) {}

    /** Formatter for a whole Test DSL document: a location inside a step reads "Bước N ('tên')". */
    public static function forDslDocument(object $document): self
    {
        return new self(dslDocument: $document);
    }

    /**
     * @return list<string>
     */
    public function format(ValidationError $error): array
    {
        $messages = [];
        foreach ($this->leaves($error) as $leaf) {
            $messages[] = $this->location($leaf).': '.$this->describe($leaf);
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return list<ValidationError>
     */
    private function leaves(ValidationError $error): array
    {
        $children = $error->subErrors();
        if ($children === []) {
            return [$error];
        }

        if (in_array($error->keyword(), ['oneOf', 'anyOf'], true)) {
            $type = $this->discriminator($error);
            if ($type !== null) {
                foreach ($children as $child) {
                    if ($this->branchType($child) === $type) {
                        return $this->leaves($child);
                    }
                }
                if ($this->allowedTypes($error) !== []) {
                    return [$error];
                }
            }

            $best = null;
            $bestScore = null;
            foreach ($children as $child) {
                $leaves = $this->leaves($child);
                $depth = max(0, ...array_map(fn (ValidationError $leaf): int => count($leaf->data()->fullPath()), $leaves));
                // A branch rejected on a discriminator (a const such as "type": "status") is another
                // shape altogether; then prefer fewer problems, then the deepest one.
                $discriminator = array_filter($leaves, fn (ValidationError $leaf): bool => $leaf->keyword() === 'const') !== [];
                $score = [$discriminator ? 0 : 1, -count($leaves), $depth];
                if ($bestScore === null || $score > $bestScore) {
                    $best = $leaves;
                    $bestScore = $score;
                }
            }

            return $best;
        }

        $result = [];
        foreach ($children as $child) {
            array_push($result, ...$this->leaves($child));
        }

        return $result;
    }

    /** The data's "type" value when the data is an object carrying one. */
    private function discriminator(ValidationError $error): ?string
    {
        $value = $error->data()->value();

        return is_object($value) && isset($value->type) && is_string($value->type) ? $value->type : null;
    }

    /** The "type" const a oneOf branch requires, read from the branch schema. */
    private function branchType(ValidationError $branchError): ?string
    {
        $schema = $branchError->schema()->info()->data();
        $const = is_object($schema) && isset($schema->properties) && is_object($schema->properties)
            && isset($schema->properties->type) && is_object($schema->properties->type)
            ? ($schema->properties->type->const ?? null)
            : null;

        return is_string($const) ? $const : null;
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(ValidationError $error): array
    {
        $types = [];
        foreach ($error->subErrors() as $child) {
            $type = $this->branchType($child);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function location(ValidationError $error): string
    {
        $path = $error->data()->fullPath();
        if ($this->dslDocument !== null && ($path[0] ?? null) === 'steps' && is_int($path[1] ?? null)) {
            return $this->stepLocation($this->dslDocument, $path);
        }

        $location = $this->pathPrefix;
        foreach ($path as $segment) {
            $location .= is_int($segment) ? "[{$segment}]" : ($location === '' ? '' : '.').$segment;
        }

        return $location === $this->pathPrefix ? $this->rootLabel : $location;
    }

    /**
     * "Bước 2 ('Tạo đơn'), assertion #1, schema.type" for steps[1].assertions[0].schema.type: the
     * step and the assertion/extract numbers are 1-based like the semantic errors, the rest of the
     * path is kept as is.
     *
     * @param  array<int, string|int>  $path  starts with "steps", <int>
     */
    private function stepLocation(object $root, array $path): string
    {
        $index = (int) $path[1];
        $steps = isset($root->steps) && is_array($root->steps) ? $root->steps : [];
        $step = $steps[$index] ?? null;
        $name = is_object($step) && isset($step->name) && is_string($step->name) && $step->name !== ''
            ? $step->name
            : null;

        $parts = ['Bước '.($index + 1).($name !== null ? " ('{$name}')" : '')];
        $rest = array_slice($path, 2);
        $itemLabels = ['assertions' => 'assertion', 'extract' => 'extract'];
        $list = $rest[0] ?? null;
        $item = $rest[1] ?? null;
        if (is_string($list) && isset($itemLabels[$list]) && is_int($item)) {
            $parts[] = $itemLabels[$list].' #'.($item + 1);
            $rest = array_slice($rest, 2);
        }

        $tail = '';
        foreach ($rest as $segment) {
            $tail .= is_int($segment) ? "[{$segment}]" : ($tail === '' ? '' : '.').$segment;
        }
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return implode(', ', $parts);
    }

    private function describe(ValidationError $error): string
    {
        $args = $error->args();
        $list = static fn (mixed $value): string => implode(', ', array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE),
            is_array($value) ? $value : [$value],
        ));
        $scalar = static fn (mixed $value): string => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return match ($error->keyword()) {
            'required' => 'thiếu trường bắt buộc '.$list($args['missing'] ?? []),
            'additionalProperties' => 'có trường không được phép: '.$list($args['properties'] ?? []),
            'unevaluatedProperties' => 'có trường không được phép: '.$list($args['properties'] ?? $args['unevaluated'] ?? []),
            'type' => 'sai kiểu dữ liệu: cần '.$this->typeName($args['expected'] ?? null).', nhận '.$this->typeName($args['type'] ?? null),
            'enum' => 'giá trị không thuộc danh sách cho phép',
            'const' => 'giá trị phải là '.$scalar($args['const'] ?? null),
            'minLength' => 'chuỗi phải có ít nhất '.$scalar($args['min'] ?? '?').' ký tự',
            'maxLength' => 'chuỗi không được dài quá '.$scalar($args['max'] ?? '?').' ký tự',
            'minimum' => 'giá trị phải lớn hơn hoặc bằng '.$scalar($args['min'] ?? '?'),
            'maximum' => 'giá trị phải nhỏ hơn hoặc bằng '.$scalar($args['max'] ?? '?'),
            'exclusiveMinimum' => 'giá trị phải lớn hơn '.$scalar($args['min'] ?? '?'),
            'exclusiveMaximum' => 'giá trị phải nhỏ hơn '.$scalar($args['max'] ?? '?'),
            'minItems' => 'mảng phải có ít nhất '.$scalar($args['min'] ?? '?').' phần tử',
            'maxItems' => 'mảng không được có quá '.$scalar($args['max'] ?? '?').' phần tử',
            'uniqueItems' => 'các phần tử của mảng phải khác nhau',
            'minProperties' => 'đối tượng phải có ít nhất '.$scalar($args['min'] ?? '?').' trường',
            'maxProperties' => 'đối tượng không được có quá '.$scalar($args['max'] ?? '?').' trường',
            'pattern' => 'chuỗi không đúng định dạng yêu cầu',
            'format' => 'giá trị không đúng định dạng '.$scalar($args['format'] ?? ''),
            'propertyNames' => 'tên trường không hợp lệ',
            'oneOf', 'anyOf' => $this->allowedTypes($error) !== [] && $this->discriminator($error) !== null
                ? 'loại "'.$this->discriminator($error).'" không hợp lệ, chỉ chấp nhận: '.implode(', ', $this->allowedTypes($error))
                : 'không khớp với dạng nào được phép',
            'not' => 'giá trị không được phép',
            '$schema', '' => 'dữ liệu không được phép',
            default => 'không hợp lệ theo quy tắc '.$error->keyword(),
        };
    }

    private function typeName(mixed $type): string
    {
        $names = [
            'object' => 'đối tượng',
            'array' => 'mảng',
            'string' => 'chuỗi',
            'integer' => 'số nguyên',
            'number' => 'số',
            'boolean' => 'giá trị đúng/sai',
            'null' => 'null',
        ];
        $types = is_array($type) ? $type : [$type];

        return implode(' hoặc ', array_map(
            static fn (mixed $item): string => is_string($item) ? ($names[$item] ?? $item) : '?',
            $types,
        ));
    }
}
