from pathlib import Path
from typing import Any

import pytest

from app.services.validator import DslValidator
from tests.repo_files import REPO_ROOT

EXAMPLES_DIR = REPO_ROOT / "docs" / "contracts" / "examples"


@pytest.fixture
def validator() -> DslValidator:
    return DslValidator()


valid_example_paths = sorted(EXAMPLES_DIR.glob("valid-*.json"))
invalid_example_paths = sorted(EXAMPLES_DIR.glob("invalid-*.json"))


@pytest.mark.parametrize("example_path", valid_example_paths, ids=lambda p: p.name)
def test_valid_fixtures_pass_schema_validation(validator: DslValidator, example_path: Path) -> None:
    """All valid fixture files from S0-02 must pass schema validation."""
    content = example_path.read_text(encoding="utf-8")
    result = validator.validate(content)
    assert result.is_valid is True, f"Failed for {example_path.name}: {result.errors}"
    assert result.schema_errors == []
    assert result.semantic_errors == []


@pytest.mark.parametrize("example_path", invalid_example_paths, ids=lambda p: p.name)
def test_invalid_fixtures_fail_schema_validation(
    validator: DslValidator, example_path: Path
) -> None:
    """All invalid fixture files from S0-02 must fail schema validation."""
    content = example_path.read_text(encoding="utf-8")
    result = validator.validate(content)
    assert result.is_valid is False, f"Expected failure for {example_path.name}"
    assert len(result.schema_errors) > 0


def test_invalid_json_string(validator: DslValidator) -> None:
    """Malformed JSON string should report a JSON decode error."""
    result = validator.validate("{invalid json")
    assert result.is_valid is False
    assert any("Lỗi cú pháp JSON" in err for err in result.schema_errors)


def test_non_dict_json(validator: DslValidator) -> None:
    """JSON that is not an object should report an error."""
    result = validator.validate('["not", "an", "object"]')
    assert result.is_valid is False
    assert len(result.schema_errors) > 0


def test_variable_lifecycle_success(validator: DslValidator) -> None:
    """Extracted variables can be used in subsequent steps."""
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Variable extraction test",
        "endpoint": {"method": "GET", "path": "/users"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "variables": {"baseUrl": "http://example.com"},
        "steps": [
            {
                "name": "login",
                "request": {
                    "method": "POST",
                    "path": "/login",
                    "headers": {"X-Base": "{{baseUrl}}"},
                },
                "extract": [{"var": "authToken", "from": "body", "path": "$.token"}],
                "assertions": [{"type": "status", "equals": 200}],
            },
            {
                "name": "get profile",
                "request": {
                    "method": "GET",
                    "path": "/profile",
                    "headers": {"Authorization": "Bearer {{authToken}}"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            },
        ],
    }
    result = validator.validate(dsl)
    assert result.is_valid is True, result.errors


def test_variable_used_before_extraction(validator: DslValidator) -> None:
    """Variable used before being defined or extracted must produce semantic error."""
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Missing variable test",
        "endpoint": {"method": "GET", "path": "/profile"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get profile without auth token",
                "request": {
                    "method": "GET",
                    "path": "/profile",
                    "headers": {"Authorization": "Bearer {{missingToken}}"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    result = validator.validate(dsl)
    assert result.is_valid is False
    assert any(
        "Biến '{{missingToken}}' được sử dụng nhưng chưa được định nghĩa" in err
        for err in result.semantic_errors
    )


def test_spec_endpoint_validation(validator: DslValidator) -> None:
    """Validate endpoints and methods against OpenAPI spec."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "post": {
                    "responses": {"201": {"description": "Created"}},
                }
            }
        }
    }

    # Unknown endpoint
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Non-existent endpoint",
        "endpoint": {"method": "POST", "path": "/unknown"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "call unknown",
                "request": {"method": "POST", "path": "/unknown"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    result = validator.validate(dsl, spec=spec)
    assert result.is_valid is False
    assert any("Endpoint 'POST /unknown' không tồn tại" in err for err in result.semantic_errors)


def test_path_parameters_validation(validator: DslValidator) -> None:
    """Validate expected and unexpected path parameters."""
    spec: dict[str, Any] = {
        "paths": {
            "/items/{id}": {
                "get": {
                    "parameters": [{"name": "id", "in": "path", "required": True}],
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    # Missing path param
    dsl_missing: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Missing path param",
        "endpoint": {"method": "GET", "path": "/items/{id}"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get item",
                "request": {"method": "GET", "path": "/items/{id}"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res_missing = validator.validate(dsl_missing, spec=spec)
    assert res_missing.is_valid is False
    assert any(
        "Thiếu tham số đường dẫn bắt buộc 'id'" in err for err in res_missing.semantic_errors
    )

    # Unexpected path param
    dsl_extra: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Extra path param",
        "endpoint": {"method": "GET", "path": "/items/{id}"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get item",
                "request": {
                    "method": "GET",
                    "path": "/items/{id}",
                    "path_params": {"id": "123", "extra": "invalid"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res_extra = validator.validate(dsl_extra, spec=spec)
    assert res_extra.is_valid is False
    assert any(
        "Tham số đường dẫn 'extra' không tồn tại" in err for err in res_extra.semantic_errors
    )


def test_query_parameters_validation(validator: DslValidator) -> None:
    """Validate query parameters against declared spec parameters."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "get": {
                    "parameters": [{"name": "limit", "in": "query"}],
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Undeclared query param",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get items",
                "request": {
                    "method": "GET",
                    "path": "/items",
                    "query": {"limit": 10, "unknown_filter": "bad"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res = validator.validate(dsl, spec=spec)
    assert res.is_valid is False
    assert any(
        "Tham số truy vấn 'unknown_filter' không tồn tại" in err for err in res.semantic_errors
    )


def test_status_assertion_validation(validator: DslValidator) -> None:
    """Check assertion status codes against declared responses."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "post": {
                    "responses": {"201": {"description": "Created"}},
                }
            }
        }
    }

    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Undeclared status code",
        "endpoint": {"method": "POST", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "create item",
                "request": {"method": "POST", "path": "/items"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res = validator.validate(dsl, spec=spec)
    assert res.is_valid is False
    assert any("Mã trạng thái HTTP '200' không được khai báo" in err for err in res.semantic_errors)


def test_all_of_preserves_array_items_schema(validator: DslValidator) -> None:
    """allOf composition must preserve array items schema and reject ghost fields."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {
                                    "allOf": [
                                        {
                                            "type": "array",
                                            "items": {
                                                "type": "object",
                                                "properties": {"name": {"type": "string"}},
                                            },
                                        }
                                    ]
                                }
                            }
                        }
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Post array items",
        "endpoint": {"method": "POST", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "post items",
                "request": {
                    "method": "POST",
                    "path": "/items",
                    "body": [{"name": "Item1"}],
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    valid_res = validator.validate(valid_dsl, spec=spec)
    assert valid_res.is_valid is True, valid_res.errors

    invalid_dsl = dict(valid_dsl)
    invalid_dsl["steps"] = [
        {
            "name": "post items with ghost",
            "request": {
                "method": "POST",
                "path": "/items",
                "body": [{"ghost": "invalid"}],
            },
            "assertions": [{"type": "status", "equals": 200}],
        }
    ]
    invalid_res = validator.validate(invalid_dsl, spec=spec)
    assert invalid_res.is_valid is False
    assert any("[0].ghost" in err for err in invalid_res.semantic_errors)


def test_one_of_and_any_of_with_ref_and_parent_properties(validator: DslValidator) -> None:
    """oneOf and anyOf branches with $ref should merge parent properties without overwriting."""
    spec: dict[str, Any] = {
        "paths": {
            "/hybrid": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {
                                    "type": "object",
                                    "properties": {"common": {"type": "string"}},
                                    "oneOf": [{"$ref": "#/components/schemas/Child"}],
                                }
                            }
                        }
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            }
        },
        "components": {
            "schemas": {
                "Child": {
                    "type": "object",
                    "properties": {"name": {"type": "string"}},
                }
            }
        },
    }

    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "oneOf with ref and parent props",
        "endpoint": {"method": "POST", "path": "/hybrid"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "post hybrid",
                "request": {
                    "method": "POST",
                    "path": "/hybrid",
                    "body": {"common": "ok", "name": "valid"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    valid_res = validator.validate(valid_dsl, spec=spec)
    assert valid_res.is_valid is True, valid_res.errors

    # Invalid field
    invalid_dsl = dict(valid_dsl)
    invalid_dsl["steps"] = [
        {
            "name": "post hybrid bad",
            "request": {
                "method": "POST",
                "path": "/hybrid",
                "body": {"common": "ok", "name": "valid", "unknown": "bad"},
            },
            "assertions": [{"type": "status", "equals": 200}],
        }
    ]
    invalid_res = validator.validate(invalid_dsl, spec=spec)
    assert invalid_res.is_valid is False
    assert any("unknown" in err for err in invalid_res.semantic_errors)


def test_additional_properties_handling(validator: DslValidator) -> None:
    """Handle dynamic keys and map schemas via additionalProperties."""
    spec: dict[str, Any] = {
        "paths": {
            "/dynamic": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {
                                    "type": "object",
                                    "additionalProperties": {"type": "string"},
                                }
                            }
                        }
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            },
            "/map": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {
                                    "type": "object",
                                    "additionalProperties": {
                                        "type": "object",
                                        "properties": {"name": {"type": "string"}},
                                    },
                                }
                            }
                        }
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            },
        }
    }

    # Dynamic keys should pass
    dyn_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Dynamic keys",
        "endpoint": {"method": "POST", "path": "/dynamic"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "post dynamic",
                "request": {
                    "method": "POST",
                    "path": "/dynamic",
                    "body": {"label": "test", "tag": "prod"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    assert validator.validate(dyn_dsl, spec=spec).is_valid is True

    # Map schema: valid value
    map_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Map entries",
        "endpoint": {"method": "POST", "path": "/map"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "post map",
                "request": {
                    "method": "POST",
                    "path": "/map",
                    "body": {"itemA": {"name": "Item A"}},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    assert validator.validate(map_dsl, spec=spec).is_valid is True

    # Map schema: invalid ghost property in map value
    invalid_map_dsl = dict(map_dsl)
    invalid_map_dsl["steps"] = [
        {
            "name": "post map invalid",
            "request": {
                "method": "POST",
                "path": "/map",
                "body": {"itemA": {"ghost": "bad"}},
            },
            "assertions": [{"type": "status", "equals": 200}],
        }
    ]
    res_map = validator.validate(invalid_map_dsl, spec=spec)
    assert res_map.is_valid is False
    assert any("itemA.ghost" in err for err in res_map.semantic_errors)


def test_inline_json_schema_syntax_validation(validator: DslValidator) -> None:
    """Validate inline JSON schema assertion syntax and reject invalid types."""
    spec: dict[str, Any] = {
        "paths": {
            "/check": {
                "get": {
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    # Valid inline schema
    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Valid inline schema",
        "endpoint": {"method": "GET", "path": "/check"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get check",
                "request": {"method": "GET", "path": "/check"},
                "assertions": [
                    {"type": "status", "equals": 200},
                    {
                        "type": "json_schema",
                        "schema": {
                            "type": "object",
                            "properties": {"id": {"type": "integer"}},
                        },
                    },
                ],
            }
        ],
    }
    assert validator.validate(valid_dsl, spec=spec).is_valid is True

    # Invalid inline schema with bogus type
    invalid_dsl = dict(valid_dsl)
    invalid_dsl["steps"] = [
        {
            "name": "get check with bogus type",
            "request": {"method": "GET", "path": "/check"},
            "assertions": [
                {"type": "status", "equals": 200},
                {
                    "type": "json_schema",
                    "schema": {"type": "imaginary_type"},
                },
            ],
        }
    ]
    res = validator.validate(invalid_dsl, spec=spec)
    assert res.is_valid is False
    assert any(
        "Cấu trúc JSON Schema inline không hợp lệ" in err and "imaginary_type" in err
        for err in res.semantic_errors
    )


def test_normalized_endpoint_list_spec_format(validator: DslValidator) -> None:
    """DslValidator supports spec as list of NormalizedEndpoint dicts."""
    normalized_endpoints: list[dict[str, Any]] = [
        {
            "id": 1,
            "method": "POST",
            "path": "/products",
            "operation_id": "createProduct",
            "summary": "Create product",
            "description": None,
            "parameters": [],
            "request_body": {
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {"title": {"type": "string"}},
                            "required": ["title"],
                        }
                    }
                }
            },
            "responses": {"201": {"description": "Created"}},
            "security": [],
        }
    ]

    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Create product test",
        "endpoint": {"method": "POST", "path": "/products"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "create",
                "request": {
                    "method": "POST",
                    "path": "/products",
                    "body": {"title": "Shampoo"},
                },
                "assertions": [{"type": "status", "equals": 201}],
            }
        ],
    }
    res = validator.validate(valid_dsl, spec=normalized_endpoints)
    assert res.is_valid is True, res.errors


def test_regression_undeclared_query_when_parameters_absent(validator: DslValidator) -> None:
    """When endpoint has no parameters declared, passing query parameters must fail."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "get": {
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Query when none declared",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get items",
                "request": {
                    "method": "GET",
                    "path": "/items",
                    "query": {"ghost": "val"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res = validator.validate(dsl, spec=spec)
    assert res.is_valid is False
    assert any("Tham số truy vấn 'ghost' không tồn tại" in err for err in res.semantic_errors)


def test_regression_undeclared_body_when_no_request_body_defined(validator: DslValidator) -> None:
    """When endpoint does not define request body, sending body must fail."""
    spec: dict[str, Any] = {
        "paths": {
            "/items": {
                "get": {
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Body when none declared",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get items with body",
                "request": {
                    "method": "GET",
                    "path": "/items",
                    "body": {"ghost": "x"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res = validator.validate(dsl, spec=spec)
    assert res.is_valid is False
    assert any("không khai báo request body" in err for err in res.semantic_errors)


def test_regression_all_of_merges_duplicate_properties_recursively(validator: DslValidator) -> None:
    """allOf branches defining properties of the same object should merge recursively."""
    spec: dict[str, Any] = {
        "paths": {
            "/profile": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {
                                    "allOf": [
                                        {
                                            "type": "object",
                                            "properties": {
                                                "profile": {
                                                    "type": "object",
                                                    "properties": {"a": {"type": "string"}},
                                                    "additionalProperties": False,
                                                }
                                            },
                                        },
                                        {
                                            "type": "object",
                                            "properties": {
                                                "profile": {
                                                    "type": "object",
                                                    "properties": {"b": {"type": "string"}},
                                                    "additionalProperties": False,
                                                }
                                            },
                                        },
                                    ]
                                }
                            }
                        }
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    # Body with both a and b should be accepted
    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Profile with both a and b",
        "endpoint": {"method": "POST", "path": "/profile"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "post profile",
                "request": {
                    "method": "POST",
                    "path": "/profile",
                    "body": {"profile": {"a": "1", "b": "2"}},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res = validator.validate(valid_dsl, spec=spec)
    assert res.is_valid is True, res.errors

    # Body with unexpected field ghost in profile should be rejected
    invalid_dsl = dict(valid_dsl)
    invalid_dsl["steps"] = [
        {
            "name": "post profile with ghost",
            "request": {
                "method": "POST",
                "path": "/profile",
                "body": {"profile": {"a": "1", "b": "2", "ghost": "x"}},
            },
            "assertions": [{"type": "status", "equals": 200}],
        }
    ]
    res_invalid = validator.validate(invalid_dsl, spec=spec)
    assert res_invalid.is_valid is False
    assert any("profile.ghost" in err for err in res_invalid.semantic_errors)


def test_regression_inline_json_schema_meta_schema_validation(validator: DslValidator) -> None:
    """Inline JSON schema assertion accepts empty schema and rejects invalid keyword types."""
    spec: dict[str, Any] = {
        "paths": {"/check": {"get": {"responses": {"200": {"description": "OK"}}}}}
    }

    # Empty schema {} is valid
    empty_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Empty schema assertion",
        "endpoint": {"method": "GET", "path": "/check"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "check empty schema",
                "request": {"method": "GET", "path": "/check"},
                "assertions": [
                    {"type": "status", "equals": 200},
                    {"type": "json_schema", "schema": {}},
                ],
            }
        ],
    }
    res_empty = validator.validate(empty_dsl, spec=spec)
    assert res_empty.is_valid is True, res_empty.errors

    # Invalid minimum: "wrong" is rejected by meta-schema
    invalid_keyword_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Invalid minimum keyword",
        "endpoint": {"method": "GET", "path": "/check"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "check wrong minimum",
                "request": {"method": "GET", "path": "/check"},
                "assertions": [
                    {"type": "status", "equals": 200},
                    {
                        "type": "json_schema",
                        "schema": {"type": "number", "minimum": "wrong"},
                    },
                ],
            }
        ],
    }
    res_bad = validator.validate(invalid_keyword_dsl, spec=spec)
    assert res_bad.is_valid is False
    assert any("Cấu trúc JSON Schema inline không hợp lệ" in err for err in res_bad.semantic_errors)


def test_all_of_merging_preserves_additional_properties_false(validator: DslValidator) -> None:
    """allOf merging must preserve additionalProperties: false even if branch 1 has true."""
    spec: dict[str, Any] = {
        "paths": {
            "/merged": {
                "post": {
                    "requestBody": {
                        "required": True,
                        "content": {
                            "application/json": {
                                "schema": {
                                    "allOf": [
                                        {
                                            "type": "object",
                                            "properties": {"a": {"type": "string"}},
                                            "additionalProperties": True,
                                        },
                                        {
                                            "type": "object",
                                            "properties": {"b": {"type": "integer"}},
                                            "additionalProperties": False,
                                        },
                                    ]
                                }
                            }
                        },
                    },
                    "responses": {"200": {"description": "OK"}},
                }
            }
        }
    }

    valid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Valid allOf payload",
        "endpoint": {"method": "POST", "path": "/merged"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {
                    "method": "POST",
                    "path": "/merged",
                    "body": {"a": "hello", "b": 42},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res_valid = validator.validate(valid_dsl, spec=spec)
    assert res_valid.is_valid is True, res_valid.errors

    invalid_dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Invalid allOf payload with unknown property",
        "endpoint": {"method": "POST", "path": "/merged"},
        "technique": "INVALID_INPUT",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {
                    "method": "POST",
                    "path": "/merged",
                    "body": {"a": "hello", "b": 42, "unknown": 1},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res_invalid = validator.validate(invalid_dsl, spec=spec)
    assert res_invalid.is_valid is False
    assert any("unknown" in err for err in res_invalid.semantic_errors)


def test_inline_schema_with_invalid_prefix_items_is_rejected(validator: DslValidator) -> None:
    """Assertion inline schema with string prefixItems must be rejected by meta-schema."""
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Invalid prefixItems schema",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "GET", "path": "/items"},
                "assertions": [
                    {
                        "type": "json_schema",
                        "schema": {"type": "array", "prefixItems": "invalid"},
                    }
                ],
            }
        ],
    }
    res = validator.validate(dsl)
    assert res.is_valid is False
    assert any("Cấu trúc JSON Schema inline không hợp lệ" in err for err in res.semantic_errors)


def test_inline_schema_with_empty_any_of_is_rejected(validator: DslValidator) -> None:
    """Assertion inline schema with empty anyOf must be rejected by meta-schema."""
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Empty anyOf schema",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "GET", "path": "/items"},
                "assertions": [
                    {
                        "type": "json_schema",
                        "schema": {"anyOf": []},
                    }
                ],
            }
        ],
    }
    res = validator.validate(dsl)
    assert res.is_valid is False
    assert any("Cấu trúc JSON Schema inline không hợp lệ" in err for err in res.semantic_errors)


def test_inline_schema_is_validated_when_spec_is_none(validator: DslValidator) -> None:
    """Inline schema validation must run even when spec is not provided."""
    dsl: dict[str, Any] = {
        "dsl_version": "1.0",
        "title": "Garbage type inline schema without spec",
        "endpoint": {"method": "GET", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "GET", "path": "/items"},
                "assertions": [
                    {
                        "type": "json_schema",
                        "schema": {"type": "garbage"},
                    }
                ],
            }
        ],
    }
    res = validator.validate(dsl, spec=None)
    assert res.is_valid is False
    assert any("Cấu trúc JSON Schema inline không hợp lệ" in err for err in res.semantic_errors)
