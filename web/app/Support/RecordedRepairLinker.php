<?php

namespace App\Support;

use JsonException;
use stdClass;

/**
 * Links the REPAIR calls of a recorded ai-service response to the case they repaired (QA S14-06).
 *
 * The official benchmark-v1.0 results predate `llm_calls[].case_index`, so their REPAIR calls carry no
 * link and M10 could not tell which case a repair belongs to. ai-service repairs one case at a time:
 * the `repair_attempts` REPAIR calls of a kept case are consecutive and appear in the order of the
 * cases in `test_cases` (REPAIR calls of a case dropped afterwards may sit in between). A window of
 * calls belongs to a case when every call targets the case's endpoint (the invalid DSL quoted in the
 * prompt, else the endpoint specification of the prompt, else the repaired DSL) and the case's title is
 * the title of the invalid DSL or of the repaired DSL of one of the calls. Each case takes the first
 * matching window after the previous case's; calls left over stay unlinked (repairs of dropped cases).
 *
 * The helpers also serve M10 for jobs imported before this link (ListTestCaseLlmLogsAction).
 */
final class RecordedRepairLinker
{
    private const DATA_BLOCK = '/<<<DATA:%1$s>>>\n(.*?)\n<<<END DATA:%1$s>>>/s';

    /**
     * Adds `case_index` to the REPAIR calls of a recorded GenerateResponse JSON that has none, keeping
     * every other value as is (objects stay objects). A response that already links a REPAIR call, or
     * that cannot be read, is returned unchanged.
     */
    public static function link(string $responseJson): string
    {
        try {
            $response = json_decode($responseJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $responseJson;
        }
        if (! $response instanceof stdClass || ! is_array($response->llm_calls ?? null) || ! is_array($response->test_cases ?? null)) {
            return $responseJson;
        }

        /** @var array<int, stdClass> $repairs llm_calls index => call */
        $repairs = [];
        foreach ($response->llm_calls as $index => $call) {
            if (! $call instanceof stdClass || ($call->purpose ?? null) !== 'REPAIR') {
                continue;
            }
            if (isset($call->case_index)) {
                return $responseJson; // already linked by ai-service
            }
            $repairs[(int) $index] = $call;
        }
        if ($repairs === []) {
            return $responseJson;
        }

        $order = array_keys($repairs);
        $next = 0;
        $linked = false;
        foreach ($response->test_cases as $caseIndex => $case) {
            $attempts = $case instanceof stdClass && is_int($case->repair_attempts ?? null) ? $case->repair_attempts : 0;
            $dsl = $case instanceof stdClass ? self::toArray($case->dsl ?? null) : null;
            if ($attempts <= 0 || $dsl === null) {
                continue;
            }
            $endpoint = self::endpointOf($dsl);
            $title = is_string($dsl['title'] ?? null) ? $dsl['title'] : null;
            if ($endpoint === null || $title === null) {
                continue;
            }

            for ($start = $next; $start + $attempts <= count($order); $start++) {
                $window = array_slice($order, $start, $attempts);
                if (! self::windowBelongs($repairs, $window, $endpoint, $title)) {
                    continue;
                }
                foreach ($window as $callIndex) {
                    $repairs[$callIndex]->case_index = (int) $caseIndex;
                }
                $next = $start + $attempts;
                $linked = true;
                break;
            }
        }

        if (! $linked) {
            return $responseJson;
        }

        return json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Endpoint ("METHOD /path") a REPAIR call worked on, from its prompt messages and raw answer.
     *
     * @param  array<mixed>  $prompt  Chat messages (or {"messages": [...]}) as stored in llm_call_logs.
     */
    public static function repairEndpoint(array $prompt, ?string $responseRaw): ?string
    {
        $invalid = self::dataBlock($prompt, 'invalid_test_case');
        $endpoint = is_array($invalid) ? self::endpointOf($invalid) : null;
        if ($endpoint !== null) {
            return $endpoint;
        }

        $spec = self::dataBlock($prompt, 'endpoints');
        if (is_array($spec) && is_string($spec['method'] ?? null) && is_string($spec['path'] ?? null)) {
            return strtoupper($spec['method']).' '.$spec['path'];
        }

        $answer = self::answer($responseRaw);

        return $answer === null ? null : self::endpointOf($answer);
    }

    /**
     * Titles of the invalid DSL quoted in a REPAIR prompt and of the repaired DSL it answered.
     *
     * @param  array<mixed>  $prompt
     * @return list<string>
     */
    public static function repairTitles(array $prompt, ?string $responseRaw): array
    {
        $titles = [];
        foreach ([self::dataBlock($prompt, 'invalid_test_case'), self::answer($responseRaw)] as $dsl) {
            if (is_array($dsl) && is_string($dsl['title'] ?? null) && $dsl['title'] !== '') {
                $titles[] = $dsl['title'];
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * @param  array<int, stdClass>  $repairs
     * @param  list<int>  $window
     */
    private static function windowBelongs(array $repairs, array $window, string $endpoint, string $title): bool
    {
        $titles = [];
        foreach ($window as $callIndex) {
            $call = $repairs[$callIndex];
            $prompt = self::toArray($call->prompt ?? null) ?? [];
            $raw = is_string($call->response_raw ?? null) ? $call->response_raw : null;
            if (self::repairEndpoint($prompt, $raw) !== $endpoint) {
                return false;
            }
            array_push($titles, ...self::repairTitles($prompt, $raw));
        }

        return in_array($title, $titles, true);
    }

    /**
     * @param  array<mixed>  $dsl
     */
    private static function endpointOf(array $dsl): ?string
    {
        $endpoint = $dsl['endpoint'] ?? null;
        if (! is_array($endpoint) || ! is_string($endpoint['method'] ?? null) || ! is_string($endpoint['path'] ?? null)) {
            return null;
        }

        return strtoupper($endpoint['method']).' '.$endpoint['path'];
    }

    /**
     * JSON object between the `<<<DATA:<name>>>>` markers of a prompt (ai-service prompt format).
     *
     * @param  array<mixed>  $prompt
     * @return array<mixed>|null
     */
    private static function dataBlock(array $prompt, string $name): ?array
    {
        $messages = isset($prompt['messages']) && is_array($prompt['messages']) ? $prompt['messages'] : $prompt;
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? null) : $message;
            if (is_string($content) && preg_match(sprintf(self::DATA_BLOCK, preg_quote($name, '/')), $content, $match) === 1) {
                $decoded = json_decode($match[1], true);

                return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    /**
     * The JSON object answered by the LLM (code fences or surrounding text tolerated), or null.
     *
     * @return array<mixed>|null
     */
    private static function answer(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            $decoded = $start !== false && $end !== false && $end > $start
                ? json_decode(substr($raw, $start, $end - $start + 1), true)
                : null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function toArray(mixed $value): ?array
    {
        if ($value instanceof stdClass || is_array($value)) {
            $decoded = json_decode((string) json_encode($value), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
