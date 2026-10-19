<?php

namespace App\Data\Experiments;

use InvalidArgumentException;

/**
 * Synthetic sandbox credentials of a benchmark (ADR-0006), declared in `ground-truth.yaml` under
 * `auth` and snapshotted into `experiments.config.auth`.
 *
 * - The full form (with tokens) lives in the experiment snapshot and is used only by the executor.
 * - The public form (`toPublicArray()`, no token) goes to generation job params and ai-service, so
 *   generators only ever see role names and the `{{auth_token_<role>}}` placeholders.
 *
 * MVP: scheme `bearer` only, i.e. `Authorization: Bearer <token>`.
 */
final readonly class AuthFixture
{
    public const HEADER = 'Authorization';

    public const VARIABLE_PREFIX = 'auth_token_';

    public const ROLE_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    private const SCHEMES = ['bearer'];

    /**
     * @param  array<string, array{token: string|null, description: string|null}>  $roles  role name => token/description
     * @param  list<array{method: string, path: string, role: string|null, forbidden_roles: list<string>}>  $operations
     */
    public function __construct(
        public string $scheme,
        public string $defaultRole,
        public array $roles,
        public array $operations = [],
    ) {}

    /**
     * Parses the ground-truth / snapshot / job-params form. Tokens are optional (the public form
     * has none); `requireTokens` makes a missing token an error (executor and snapshot).
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(mixed $raw, bool $requireTokens = false): self
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException('auth phải là object.');
        }

        $scheme = is_string($raw['scheme'] ?? null) ? strtolower($raw['scheme']) : '';
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException('auth.scheme chỉ hỗ trợ "bearer".');
        }

        $rawRoles = $raw['roles'] ?? null;
        $roles = [];
        if (is_array($rawRoles) && array_is_list($rawRoles)) {
            // Public form: [{name, description}].
            foreach ($rawRoles as $item) {
                if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                    throw new InvalidArgumentException('auth.roles[] phải có name.');
                }
                $roles[$item['name']] = $item;
            }
        } elseif (is_array($rawRoles)) {
            $roles = $rawRoles;
        }
        if ($roles === []) {
            throw new InvalidArgumentException('auth.roles không được rỗng.');
        }

        $parsedRoles = [];
        foreach ($roles as $name => $role) {
            $name = (string) $name;
            if (preg_match(self::ROLE_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException("Tên vai trò '{$name}' không hợp lệ (a-z, 0-9, _).");
            }
            $role = is_array($role) ? $role : [];
            $token = is_string($role['token'] ?? null) && $role['token'] !== '' ? $role['token'] : null;
            if ($requireTokens && $token === null) {
                throw new InvalidArgumentException("Vai trò '{$name}' thiếu token.");
            }
            $parsedRoles[$name] = [
                'token' => $token,
                'description' => is_string($role['description'] ?? null) ? mb_substr($role['description'], 0, 500) : null,
            ];
        }

        $defaultRole = is_string($raw['default_role'] ?? null) ? $raw['default_role'] : '';
        if (! isset($parsedRoles[$defaultRole])) {
            throw new InvalidArgumentException('auth.default_role phải là một vai trò đã khai báo.');
        }

        $operations = [];
        $rawOperations = $raw['operations'] ?? [];
        if (! is_array($rawOperations)) {
            throw new InvalidArgumentException('auth.operations phải là danh sách.');
        }
        foreach (array_values($rawOperations) as $index => $operation) {
            $method = is_array($operation) && is_string($operation['method'] ?? null) ? strtoupper($operation['method']) : '';
            $path = is_array($operation) && is_string($operation['path'] ?? null) ? $operation['path'] : '';
            if ($method === '' || ! str_starts_with($path, '/')) {
                throw new InvalidArgumentException("auth.operations[{$index}] thiếu method/path hợp lệ.");
            }
            $role = is_string($operation['role'] ?? null) ? $operation['role'] : null;
            if ($role !== null && ! isset($parsedRoles[$role])) {
                throw new InvalidArgumentException("auth.operations[{$index}].role '{$role}' chưa được khai báo.");
            }
            $forbidden = [];
            foreach (is_array($operation['forbidden_roles'] ?? null) ? $operation['forbidden_roles'] : [] as $forbiddenRole) {
                if (! is_string($forbiddenRole) || ! isset($parsedRoles[$forbiddenRole])) {
                    throw new InvalidArgumentException("auth.operations[{$index}].forbidden_roles chứa vai trò chưa khai báo.");
                }
                $forbidden[] = $forbiddenRole;
            }
            if (in_array($role ?? $defaultRole, $forbidden, true)) {
                throw new InvalidArgumentException("auth.operations[{$index}]: vai trò dùng cho operation nằm trong forbidden_roles.");
            }
            $operations[] = [
                'method' => $method,
                'path' => $path,
                'role' => $role,
                'forbidden_roles' => array_values(array_unique($forbidden)),
            ];
        }

        return new self($scheme, $defaultRole, $parsedRoles, $operations);
    }

    /**
     * Lenient variant: null for absent/invalid input (old experiments, plain M11 jobs).
     */
    public static function tryFrom(mixed $raw, bool $requireTokens = false): ?self
    {
        if ($raw === null) {
            return null;
        }

        try {
            return self::fromArray($raw, $requireTokens);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function variableName(string $role): string
    {
        return self::VARIABLE_PREFIX.$role;
    }

    /**
     * Header value referencing the role's run-time variable (never the token itself).
     */
    public static function headerValue(string $role): string
    {
        return 'Bearer {{'.self::variableName($role).'}}';
    }

    /**
     * Names of the run-time variables the executor defines (`auth_token_<role>`).
     *
     * @return list<string>
     */
    public function variableNames(): array
    {
        return array_map(fn (string $role): string => self::variableName($role), array_keys($this->roles));
    }

    /**
     * Run-time variables (role tokens). Roles without a token are skipped.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $variables = [];
        foreach ($this->roles as $role => $data) {
            if ($data['token'] !== null) {
                $variables[self::variableName($role)] = $data['token'];
            }
        }

        return $variables;
    }

    public function hasTokens(): bool
    {
        foreach ($this->roles as $data) {
            if ($data['token'] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Role used for an operation when the case does not set Authorization itself.
     */
    public function roleFor(string $method, string $path): string
    {
        return $this->operation($method, $path)['role'] ?? $this->defaultRole;
    }

    /**
     * @return list<string>
     */
    public function forbiddenRolesFor(string $method, string $path): array
    {
        return $this->operation($method, $path)['forbidden_roles'] ?? [];
    }

    /**
     * @return array{method: string, path: string, role: string|null, forbidden_roles: list<string>}|null
     */
    private function operation(string $method, string $path): ?array
    {
        foreach ($this->operations as $operation) {
            if ($operation['method'] === strtoupper($method) && $operation['path'] === $path) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Snapshot form stored in experiments.config.auth (tokens included, ground-truth layout).
     *
     * @return array{scheme: string, default_role: string, roles: array<string, array{token: string|null, description: string|null}>, operations: list<array{method: string, path: string, role: string|null, forbidden_roles: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'default_role' => $this->defaultRole,
            'roles' => $this->roles,
            'operations' => $this->operations,
        ];
    }

    /**
     * Form sent to generators (job params, ai-service `GenerateRequest.auth`): no token.
     *
     * @return array{scheme: string, default_role: string, roles: list<array{name: string, description: string|null}>, operations: list<array{method: string, path: string, role: string|null, forbidden_roles: list<string>}>}
     */
    public function toPublicArray(): array
    {
        $roles = [];
        foreach ($this->roles as $name => $data) {
            $roles[] = ['name' => $name, 'description' => $data['description']];
        }

        return [
            'scheme' => $this->scheme,
            'default_role' => $this->defaultRole,
            'roles' => $roles,
            'operations' => $this->operations,
        ];
    }
}
