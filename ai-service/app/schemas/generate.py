from typing import Annotated, Any, Literal, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator

HttpMethod = Literal["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"]

Technique = Literal[
    "HAPPY_PATH",
    "BOUNDARY",
    "EQUIVALENCE",
    "INVALID_INPUT",
    "MISSING_REQUIRED",
    "TYPE_MISMATCH",
    "AUTH",
    "BUSINESS_RULE",
    "SEQUENCE",
]


class NormalizedEndpoint(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: int = Field(..., ge=1)
    method: HttpMethod
    path: str = Field(..., pattern=r"^/")
    # Every key is required by the contract (NormalizedEndpoint.required); the nullable
    # ones must still be sent explicitly as null (S13-03).
    operation_id: str | None = Field(...)
    summary: str | None = Field(...)
    description: str | None = Field(...)
    parameters: list[dict[str, Any]] | None = Field(...)
    request_body: dict[str, Any] | None = Field(...)
    responses: dict[str, Any] = Field(...)
    security: list[dict[str, Any]] | None = Field(...)


class BusinessRule(BaseModel):
    model_config = ConfigDict(extra="forbid")

    code: str = Field(..., min_length=1, max_length=50)
    description: str = Field(..., min_length=1, max_length=5000)
    # Required by the contract (BusinessRule.required); null means a project-wide rule.
    endpoint_id: int | None = Field(..., ge=1)


AuthRoleName = Annotated[str, Field(pattern=r"^[a-z][a-z0-9_]{0,30}$")]

#: Prefix of the run-time credential variables the web executor defines (ADR-0006).
AUTH_VARIABLE_PREFIX = "auth_token_"


class AuthRole(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: AuthRoleName
    description: str | None = Field(default=None, max_length=500)


class AuthOperation(BaseModel):
    model_config = ConfigDict(extra="forbid")

    method: HttpMethod
    path: str = Field(..., pattern=r"^/")
    role: AuthRoleName | None = None
    forbidden_roles: list[AuthRoleName] = Field(default_factory=list)


class AuthContext(BaseModel):
    """Credential context of an experiment (ADR-0006). It never carries tokens:
    ``extra="forbid"`` rejects any token field. The executor defines ``auth_token_<role>``
    and sends ``Authorization: Bearer {{auth_token_<role>}}`` to secured operations of
    non-AUTH cases that do not set the header themselves."""

    model_config = ConfigDict(extra="forbid")

    scheme: Literal["bearer"]
    default_role: AuthRoleName
    roles: list[AuthRole] = Field(..., min_length=1)
    operations: list[AuthOperation] = Field(default_factory=list)

    @model_validator(mode="after")
    def validate_roles(self) -> Self:
        names = [role.name for role in self.roles]
        if len(set(names)) != len(names):
            raise ValueError("auth.roles must have unique names")
        if self.default_role not in names:
            raise ValueError("auth.default_role must be one of auth.roles")
        for op in self.operations:
            for role in [op.role, *op.forbidden_roles]:
                if role is not None and role not in names:
                    raise ValueError(f"auth.operations: unknown role {role}")
        return self

    def variable_names(self) -> list[str]:
        return [f"{AUTH_VARIABLE_PREFIX}{role.name}" for role in self.roles]

    def role_for(self, method: str, path: str) -> str:
        for op in self.operations:
            if op.method == method.upper() and op.path == path and op.role is not None:
                return op.role
        return self.default_role

    def forbidden_roles_for(self, method: str, path: str) -> list[str]:
        for op in self.operations:
            if op.method == method.upper() and op.path == path:
                return list(op.forbidden_roles)
        return []


class GenerateOptions(BaseModel):
    model_config = ConfigDict(extra="forbid")

    # All six keys are required by the contract (GenerateOptions.required); the web
    # client always sends them (AiGenerateRequest::toArray merges defaults), S13-03.
    max_cases_per_endpoint: int = Field(..., ge=1, le=100)
    techniques: list[Technique] = Field(...)
    model: str | None = Field(...)
    temperature: float = Field(..., ge=0.0, le=2.0)
    seed: int | None = Field(...)
    max_repair_attempts: int = Field(..., ge=0, le=10)


class GenerateRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    job_id: int = Field(..., ge=1)
    strategy: Literal["LLM", "HYBRID"]
    endpoints: list[NormalizedEndpoint] = Field(..., min_length=1)
    # Required by the contract (GenerateRequest.required); an empty list is valid,
    # a missing key is not (S13-03).
    business_rules: list[BusinessRule] = Field(...)
    seed_cases: list[dict[str, Any]] = Field(...)
    options: GenerateOptions
    auth: AuthContext | None = None

    def context_variables(self) -> list[str]:
        """Run-time variables the web executor defines before step 1 (ADR-0006)."""
        return self.auth.variable_names() if self.auth is not None else []

    @model_validator(mode="after")
    def validate_strategy_seed_cases(self) -> Self:
        if self.strategy == "HYBRID" and not self.seed_cases:
            raise ValueError("seed_cases must have at least 1 item when strategy is HYBRID")
        if self.seed_cases:
            from app.services.validator import DslValidator

            validator = DslValidator()
            spec_endpoints = [ep.model_dump() for ep in self.endpoints]
            for idx, sc in enumerate(self.seed_cases):
                if not isinstance(sc, dict) or not sc:
                    raise ValueError(f"seed_cases[{idx}]: seed case must be a non-empty object")
                sc_dsl = sc.get("dsl") if isinstance(sc.get("dsl"), dict) else sc
                if not isinstance(sc_dsl, dict) or not sc_dsl:
                    raise ValueError(f"seed_cases[{idx}]: test case DSL must be a non-empty object")
                val_res = validator.validate(
                    sc_dsl, spec=spec_endpoints, context_variables=self.context_variables()
                )
                if not val_res.is_valid:
                    err = val_res.errors[0] if val_res.errors else "Invalid Test DSL"
                    raise ValueError(f"seed_cases[{idx}]: {err}")
        return self


class ChatMessage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    role: Literal["system", "user", "assistant"]
    content: str


class GeneratedTestCase(BaseModel):
    model_config = ConfigDict(extra="forbid")

    dsl: dict[str, Any]
    status: Literal["VALID", "INVALID", "DUPLICATE"]
    validation_errors: list[str] = Field(default_factory=list)
    repair_attempts: int = Field(default=0, ge=0)
    fingerprint: str | None = Field(default=None, pattern=r"^[a-f0-9]{64}$")


class LlmCall(BaseModel):
    model_config = ConfigDict(extra="forbid")

    purpose: Literal["GENERATE", "REPAIR"]
    provider: str = Field(..., min_length=1)
    model: str = Field(..., min_length=1)
    prompt: list[ChatMessage] = Field(..., min_length=1)
    response_raw: str
    temperature: float = Field(..., ge=0.0, le=2.0)
    seed: int | None = None
    prompt_tokens: int | None = Field(default=None, ge=0)
    completion_tokens: int | None = Field(default=None, ge=0)
    latency_ms: int = Field(..., ge=0)
    error: str | None = None
    # Index in GenerateResponse.test_cases of the case a REPAIR call worked on, so the web can
    # link the log to that test case (M10, QA S13-03). None for GENERATE calls and for repairs
    # of cases that were not returned (over the per-endpoint cap).
    case_index: int | None = Field(default=None, ge=0)


class GenerationStats(BaseModel):
    model_config = ConfigDict(extra="forbid")

    generated: int = Field(default=0, ge=0)
    valid: int = Field(default=0, ge=0)
    invalid: int = Field(default=0, ge=0)
    repaired: int = Field(default=0, ge=0)
    duplicates: int = Field(default=0, ge=0)


class GenerateResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    test_cases: list[GeneratedTestCase]
    llm_calls: list[LlmCall]
    stats: GenerationStats
