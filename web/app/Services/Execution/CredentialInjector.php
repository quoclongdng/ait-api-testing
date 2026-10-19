<?php

namespace App\Services\Execution;

use App\Data\Experiments\AuthFixture;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Support\OpenApiSecurity;
use stdClass;

/**
 * Default credential of an experiment run (ADR-0006 §3), the same rule for RULE, LLM and HYBRID:
 *
 * every step that calls an operation whose effective OpenAPI security requires a credential, and
 * whose request has no `Authorization` header (case-insensitive), gets
 * `Authorization: Bearer {{auth_token_<role>}}` (role of the operation in the fixture, otherwise
 * the default role). AUTH cases are left untouched: they control credentials themselves (missing,
 * invalid or another role's token). A header the case sets itself always wins.
 *
 * Only the `{{auth_token_<role>}}` placeholder is written into the DSL; the executor resolves it
 * from the run variables, and request logs mask the header (§9). The Test DSL shape is unchanged (D05).
 */
class CredentialInjector
{
    /**
     * @param  array<string, mixed>|object  $dsl  DSL as stored (arrays, or stdClass objects keeping `{}`)
     * @return array<string, mixed>|object a copy; the input is never modified
     */
    public function apply(array|object $dsl, AuthFixture $auth, ?ApiSpec $spec): array|object
    {
        $secured = $this->securedOperations($spec);
        if ($secured === []) {
            return $dsl;
        }

        if (is_object($dsl)) {
            // Deep copy without a JSON round trip, so numbers and `{}` keep their exact form.
            $copy = $this->deepCopy($dsl);
            if (! $copy instanceof stdClass || ($copy->technique ?? null) === TestTechnique::Auth->value || ! is_array($copy->steps ?? null)) {
                return $dsl;
            }
            foreach ($copy->steps as $step) {
                if (! $step instanceof stdClass || ! ($step->request ?? null) instanceof stdClass) {
                    continue;
                }
                $request = $step->request;
                $role = $this->roleFor($auth, $secured, $request->method ?? null, $request->path ?? null);
                if ($role === null) {
                    continue;
                }
                $headers = $request->headers ?? null;
                $headers = $headers instanceof stdClass ? $headers : new stdClass;
                if ($this->hasAuthorization(array_keys(get_object_vars($headers)))) {
                    continue;
                }
                $headers->{AuthFixture::HEADER} = AuthFixture::headerValue($role);
                $request->headers = $headers;
            }

            return $copy;
        }

        if (($dsl['technique'] ?? null) === TestTechnique::Auth->value || ! is_array($dsl['steps'] ?? null)) {
            return $dsl;
        }
        foreach ($dsl['steps'] as $index => $step) {
            if (! is_array($step) || ! is_array($step['request'] ?? null)) {
                continue;
            }
            $role = $this->roleFor($auth, $secured, $step['request']['method'] ?? null, $step['request']['path'] ?? null);
            if ($role === null) {
                continue;
            }
            $headers = is_array($step['request']['headers'] ?? null) ? $step['request']['headers'] : [];
            if ($this->hasAuthorization(array_keys($headers))) {
                continue;
            }
            $headers[AuthFixture::HEADER] = AuthFixture::headerValue($role);
            $dsl['steps'][$index]['request']['headers'] = $headers;
        }

        return $dsl;
    }

    private function deepCopy(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = $this->deepCopy($item);
            }

            return $copy;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->deepCopy($item), $value);
        }

        return $value;
    }

    /**
     * Operation of the step, as OpenAPI path matching does: a literal path (`/orders/mine`) wins over a
     * templated one (`/orders/{order_id}`) whatever the spec order, and among templated candidates the
     * one with the fewest parameters (the most literal segments) wins.
     *
     * @param  list<array{method: string, path: string, pattern: string, params: int}>  $secured
     */
    private function roleFor(AuthFixture $auth, array $secured, mixed $method, mixed $path): ?string
    {
        if (! is_string($method) || ! is_string($path)) {
            return null;
        }
        $method = strtoupper($method);
        $best = null;
        foreach ($secured as $operation) {
            if ($operation['method'] !== $method) {
                continue;
            }
            if ($operation['path'] === $path) {
                return $auth->roleFor($method, $operation['path']);
            }
            if (preg_match($operation['pattern'], $path) === 1 && ($best === null || $operation['params'] < $best['params'])) {
                $best = $operation;
            }
        }

        return $best === null ? null : $auth->roleFor($method, $best['path']);
    }

    /**
     * @param  list<int|string>  $names
     */
    private function hasAuthorization(array $names): bool
    {
        foreach ($names as $name) {
            if (strcasecmp((string) $name, AuthFixture::HEADER) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Operations of the spec that require a credential, with a regex matching concrete paths.
     *
     * @return list<array{method: string, path: string, pattern: string, params: int}>
     */
    private function securedOperations(?ApiSpec $spec): array
    {
        if ($spec === null) {
            return [];
        }

        $endpoints = $spec->relationLoaded('endpoints') ? $spec->endpoints : $spec->endpoints()->get();
        $secured = [];
        foreach ($endpoints as $endpoint) {
            /** @var Endpoint $endpoint */
            if (! OpenApiSecurity::requiresAuth($endpoint->security)) {
                continue;
            }
            $parts = preg_split('/(\{[^}\/]+\})/', $endpoint->path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $regex = '';
            $params = 0;
            foreach ($parts as $part) {
                if (preg_match('/^\{[^}\/]+\}$/', $part) === 1) {
                    $regex .= '[^/]+';
                    $params++;
                } else {
                    $regex .= preg_quote($part, '~');
                }
            }
            $secured[] = [
                'method' => $endpoint->method->value,
                'path' => $endpoint->path,
                'pattern' => '~^'.$regex.'$~',
                'params' => $params,
            ];
        }

        return $secured;
    }
}
