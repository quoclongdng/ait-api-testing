"""Canonical Witness Test DSL Generator for all 32 Benchmark Seeded Bugs.

Produces valid AIT Test DSL v1.0 test cases exercising the exact conditions
of each seeded bug across simple-store (10), ecommerce-order (12), and appointment-booking (10).
Strictly adheres to docs/contracts/test-dsl.schema.json, OpenAPI path templates, and Decision D14.
"""

from __future__ import annotations

from typing import Any


def strip_dsl_metadata(case: dict[str, Any]) -> dict[str, Any]:
    """Return pure AIT Test DSL v1.0 payload by stripping runtime metadata like 'id'."""
    dsl = dict(case)
    dsl.pop("id", None)
    return dsl


def generate_witness_cases_for_benchmark(slug: str) -> list[dict[str, Any]]:
    """Return a complete suite of Test DSL v1.0 cases targeting all bugs in the benchmark."""
    if slug == "simple-store":
        return _generate_simple_store_witnesses()
    if slug == "ecommerce-order":
        return _generate_ecommerce_order_witnesses()
    if slug == "appointment-booking":
        return _generate_appointment_booking_witnesses()
    raise ValueError(f"Unknown benchmark slug: {slug}")


def _generate_simple_store_witnesses() -> list[dict[str, Any]]:
    return [
        {
            "id": "BUG-001",
            "dsl_version": "1.0",
            "title": "BUG-001: Missing required name validation",
            "endpoint": {"method": "POST", "path": "/products"},
            "technique": "MISSING_REQUIRED",
            "source": "RULE",
            "steps": [
                {
                    "name": "create product without name",
                    "request": {
                        "method": "POST",
                        "path": "/products",
                        "headers": {"Content-Type": "application/json"},
                        "body": {
                            "sku": "BUG001-TEST",
                            "price": 49.99,
                            "category_id": 1,
                            "quantity": 10,
                        },
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-002",
            "dsl_version": "1.0",
            "title": "BUG-002: Negative price boundary validation",
            "endpoint": {"method": "POST", "path": "/products"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "create product with negative price",
                    "request": {
                        "method": "POST",
                        "path": "/products",
                        "headers": {"Content-Type": "application/json"},
                        "body": {
                            "name": "Negative Price Item",
                            "sku": "BUG002-TEST",
                            "price": -15.50,
                            "category_id": 1,
                            "quantity": 5,
                        },
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-003",
            "dsl_version": "1.0",
            "title": "BUG-003: Creation status code should be 201",
            "endpoint": {"method": "POST", "path": "/products"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [
                {
                    "name": "create valid product",
                    "request": {
                        "method": "POST",
                        "path": "/products",
                        "headers": {"Content-Type": "application/json"},
                        "body": {
                            "name": "Valid Product 3",
                            "sku": "VALID-003",
                            "price": 15.0,
                            "category_id": 1,
                        },
                    },
                    "assertions": [{"type": "status", "equals": 201}],
                }
            ],
        },
        {
            "id": "BUG-004",
            "dsl_version": "1.0",
            "title": "BUG-004: Non-existent product ID status code should be 404",
            "endpoint": {"method": "GET", "path": "/products/{id}"},
            "technique": "INVALID_INPUT",
            "source": "RULE",
            "steps": [
                {
                    "name": "get non existent product",
                    "request": {
                        "method": "GET",
                        "path": "/products/{id}",
                        "path_params": {"id": 999999},
                    },
                    "assertions": [{"type": "status", "equals": 404}],
                }
            ],
        },
        {
            "id": "BUG-005",
            "dsl_version": "1.0",
            "title": "BUG-005: Product response must contain sku field",
            "endpoint": {"method": "GET", "path": "/products/{id}"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [
                {
                    "name": "get existing product 1",
                    "request": {
                        "method": "GET",
                        "path": "/products/{id}",
                        "path_params": {"id": 1},
                    },
                    "assertions": [
                        {"type": "status", "equals": 200},
                        {"type": "json_path", "path": "$.sku", "op": "exists"},
                    ],
                }
            ],
        },
        {
            "id": "BUG-006",
            "dsl_version": "1.0",
            "title": "BUG-006: Stock upper boundary validation",
            "endpoint": {"method": "PUT", "path": "/products/{id}"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "update quantity exceeding max 100000",
                    "request": {
                        "method": "PUT",
                        "path": "/products/{id}",
                        "path_params": {"id": 1},
                        "headers": {"Content-Type": "application/json"},
                        "body": {"quantity": 150000},
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-007",
            "dsl_version": "1.0",
            "title": "BUG-007: Negative limit pagination validation",
            "endpoint": {"method": "GET", "path": "/products"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "list products with limit -1",
                    "request": {"method": "GET", "path": "/products", "query": {"limit": -1}},
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-008",
            "dsl_version": "1.0",
            "title": "BUG-008: Unique category slug constraint",
            "endpoint": {"method": "POST", "path": "/categories"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "create duplicate category slug",
                    "request": {
                        "method": "POST",
                        "path": "/categories",
                        "headers": {"Content-Type": "application/json"},
                        "body": {"name": "Duplicate Books", "slug": "books"},
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-009",
            "dsl_version": "1.0",
            "title": "BUG-009: Product deletion status code should be 204",
            "endpoint": {"method": "DELETE", "path": "/products/{id}"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [
                {
                    "name": "delete product 1",
                    "request": {
                        "method": "DELETE",
                        "path": "/products/{id}",
                        "path_params": {"id": 1},
                    },
                    "assertions": [{"type": "status", "equals": 204}],
                }
            ],
        },
        {
            "id": "BUG-010",
            "dsl_version": "1.0",
            "title": "BUG-010: String ID input type validation",
            "endpoint": {"method": "GET", "path": "/products/{id}"},
            "technique": "TYPE_MISMATCH",
            "source": "RULE",
            "steps": [
                {
                    "name": "get product with non-integer id",
                    "request": {
                        "method": "GET",
                        "path": "/products/{id}",
                        "path_params": {"id": "invalid-str"},
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
    ]


def _generate_ecommerce_order_witnesses() -> list[dict[str, Any]]:
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    bob_headers = {"Authorization": "Bearer customer-bob-token"}
    return [
        {
            "id": "BUG-011",
            "dsl_version": "1.0",
            "title": "BUG-011: Add cart item with quantity zero boundary",
            "endpoint": {"method": "POST", "path": "/cart/items"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "add item with quantity 0",
                    "request": {
                        "method": "POST",
                        "path": "/cart/items",
                        "headers": alice_headers,
                        "body": {"product_id": 2, "quantity": 0},
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-012",
            "dsl_version": "1.0",
            "title": "BUG-012: Quantity exceeding stock inventory",
            "endpoint": {"method": "POST", "path": "/cart/items"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "add item exceeding stock",
                    "request": {
                        "method": "POST",
                        "path": "/cart/items",
                        "headers": alice_headers,
                        "body": {"product_id": 1, "quantity": 10},
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-013",
            "dsl_version": "1.0",
            "title": "BUG-013: Checkout empty cart sequence error",
            "endpoint": {"method": "POST", "path": "/checkout"},
            "technique": "SEQUENCE",
            "source": "HYBRID",
            "steps": [
                {
                    "name": "checkout with empty cart",
                    "request": {
                        "method": "POST",
                        "path": "/checkout",
                        "headers": bob_headers,
                        "body": {"cart_id": 102},
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-014",
            "dsl_version": "1.0",
            "title": "BUG-014: Checkout returns HTTP 201 Created",
            "endpoint": {"method": "POST", "path": "/checkout"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [
                {
                    "name": "add item to cart 101",
                    "request": {
                        "method": "POST",
                        "path": "/cart/items",
                        "headers": alice_headers,
                        "body": {"product_id": 2, "quantity": 1},
                    },
                    "assertions": [{"type": "status", "equals": 201}],
                },
                {
                    "name": "checkout non-empty cart",
                    "request": {
                        "method": "POST",
                        "path": "/checkout",
                        "headers": alice_headers,
                        "body": {"cart_id": 101},
                    },
                    "assertions": [{"type": "status", "equals": 201}],
                },
            ],
        },
        {
            "id": "BUG-015",
            "dsl_version": "1.0",
            "title": "BUG-015: Duplicate order payment sequence check",
            "endpoint": {"method": "POST", "path": "/orders/{order_id}/pay"},
            "technique": "SEQUENCE",
            "source": "HYBRID",
            "steps": [
                {
                    "name": "first pay of order 901",
                    "request": {
                        "method": "POST",
                        "path": "/orders/{order_id}/pay",
                        "path_params": {"order_id": 901},
                        "headers": bob_headers,
                    },
                    "assertions": [{"type": "status", "equals": 200}],
                },
                {
                    "name": "pay order already paid",
                    "request": {
                        "method": "POST",
                        "path": "/orders/{order_id}/pay",
                        "path_params": {"order_id": 901},
                        "headers": bob_headers,
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                },
            ],
        },
        {
            "id": "BUG-016",
            "dsl_version": "1.0",
            "title": "BUG-016: Cancel shipped order business rule check",
            "endpoint": {"method": "POST", "path": "/orders/{order_id}/cancel"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "cancel already shipped order 902",
                    "request": {
                        "method": "POST",
                        "path": "/orders/{order_id}/cancel",
                        "path_params": {"order_id": 902},
                        "headers": alice_headers,
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-017",
            "dsl_version": "1.0",
            "title": "BUG-017: Customer forbidden from admin orders",
            "endpoint": {"method": "GET", "path": "/admin/orders"},
            "technique": "AUTH",
            "source": "HYBRID",
            "steps": [
                {
                    "name": "access admin orders with customer token",
                    "request": {
                        "method": "GET",
                        "path": "/admin/orders",
                        "headers": alice_headers,
                    },
                    "assertions": [{"type": "status", "equals": 403}],
                }
            ],
        },
        {
            "id": "BUG-018",
            "dsl_version": "1.0",
            "title": "BUG-018: Customer forbidden from viewing other order (IDOR)",
            "endpoint": {"method": "GET", "path": "/orders/{order_id}"},
            "technique": "AUTH",
            "source": "HYBRID",
            "steps": [
                {
                    "name": "Alice tries to view Bob order 901",
                    "request": {
                        "method": "GET",
                        "path": "/orders/{order_id}",
                        "path_params": {"order_id": 901},
                        "headers": alice_headers,
                    },
                    "assertions": [{"type": "status", "equals": 403}],
                }
            ],
        },
        {
            "id": "BUG-019",
            "dsl_version": "1.0",
            "title": "BUG-019: Order total_amount must be number not string",
            "endpoint": {"method": "POST", "path": "/orders"},
            "technique": "TYPE_MISMATCH",
            "source": "RULE",
            "steps": [
                {
                    "name": "create order verify total_amount schema",
                    "request": {
                        "method": "POST",
                        "path": "/orders",
                        "headers": alice_headers,
                        "body": {"items": [{"product_id": 2, "quantity": 1}]},
                    },
                    "assertions": [
                        {"type": "status", "equals": 201},
                        {"type": "json_schema", "schema_ref": "#/components/schemas/OrderResponse"},
                    ],
                }
            ],
        },
        {
            "id": "BUG-020",
            "dsl_version": "1.0",
            "title": "BUG-020: Coupon minimum order amount business rule",
            "endpoint": {"method": "POST", "path": "/coupons/apply"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "add item to cart 101",
                    "request": {
                        "method": "POST",
                        "path": "/cart/items",
                        "headers": alice_headers,
                        "body": {"product_id": 2, "quantity": 1},
                    },
                    "assertions": [{"type": "status", "equals": 201}],
                },
                {
                    "name": "apply coupon without meeting minimum",
                    "request": {
                        "method": "POST",
                        "path": "/coupons/apply",
                        "headers": alice_headers,
                        "body": {"cart_id": 101, "code": "SAVE10"},
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                },
            ],
        },
        {
            "id": "BUG-021",
            "dsl_version": "1.0",
            "title": "BUG-021: Delete non-existent cart item returns 404",
            "endpoint": {"method": "DELETE", "path": "/cart/items/{item_id}"},
            "technique": "INVALID_INPUT",
            "source": "RULE",
            "steps": [
                {
                    "name": "delete missing cart item",
                    "request": {
                        "method": "DELETE",
                        "path": "/cart/items/{item_id}",
                        "path_params": {"item_id": 9999},
                        "headers": alice_headers,
                    },
                    "assertions": [{"type": "status", "equals": 404}],
                }
            ],
        },
        {
            "id": "BUG-022",
            "dsl_version": "1.0",
            "title": "BUG-022: Empty cart items must be array not null",
            "endpoint": {"method": "GET", "path": "/cart"},
            "technique": "TYPE_MISMATCH",
            "source": "RULE",
            "steps": [
                {
                    "name": "get Bob empty cart items array",
                    "request": {
                        "method": "GET",
                        "path": "/cart",
                        "headers": bob_headers,
                    },
                    "assertions": [
                        {"type": "status", "equals": 200},
                        {"type": "json_path", "path": "$.items", "op": "type", "value": "array"},
                    ],
                }
            ],
        },
    ]


def _generate_appointment_booking_witnesses() -> list[dict[str, Any]]:
    alice_headers = {"Authorization": "Bearer patient-alice-token"}
    bob_headers = {"Authorization": "Bearer patient-bob-token"}

    slots_28 = [
        "SLOT-0800",
        "SLOT-0830",
        "SLOT-0930",
        "SLOT-1000",
        "SLOT-1030",
        "SLOT-1100",
        "SLOT-1130",
        "SLOT-1300",
        "SLOT-1330",
        "SLOT-1400",
        "SLOT-1430",
        "SLOT-1500",
        "SLOT-1530",
        "SLOT-1600",
        "SLOT-1630",
        "SLOT-1700",
        "SLOT-1730",
        "SLOT-1800",
        "SLOT-1830",
    ]
    quota_steps_28: list[dict[str, Any]] = [
        {
            "name": f"fill quota slot {s}",
            "request": {
                "method": "POST",
                "path": "/appointments",
                "headers": alice_headers,
                "body": {
                    "doctor_id": 101,
                    "appointment_date": "2026-10-15",
                    "slot_id": s,
                    "patient_phone": "+1234567890",
                },
            },
            "assertions": [{"type": "status", "equals": 201}],
        }
        for s in slots_28
    ]
    quota_steps_28.append(
        {
            "name": "book 21st slot exceeding quota",
            "request": {
                "method": "POST",
                "path": "/appointments",
                "headers": alice_headers,
                "body": {
                    "doctor_id": 101,
                    "appointment_date": "2026-10-15",
                    "slot_id": "SLOT-1900",
                    "patient_phone": "+1234567890",
                },
            },
            "assertions": [{"type": "status", "equals": 409}],
        }
    )

    return [
        {
            "id": "BUG-023",
            "dsl_version": "1.0",
            "title": "BUG-023: Appointment date in past boundary validation",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "book with past date",
                    "request": {
                        "method": "POST",
                        "path": "/appointments",
                        "headers": alice_headers,
                        "body": {
                            "doctor_id": 101,
                            "appointment_date": "2020-01-01",
                            "slot_id": "SLOT-0900",
                            "patient_phone": "+1234567890",
                        },
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
        {
            "id": "BUG-024",
            "dsl_version": "1.0",
            "title": "BUG-024: Double booking same doctor and slot conflict",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "book slot already taken by Alice (501)",
                    "request": {
                        "method": "POST",
                        "path": "/appointments",
                        "headers": bob_headers,
                        "body": {
                            "doctor_id": 101,
                            "appointment_date": "2026-10-15",
                            "slot_id": "SLOT-0900",
                            "patient_phone": "+1987654321",
                        },
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-025",
            "dsl_version": "1.0",
            "title": "BUG-025: Clinic off-day booking business rule",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "book on clinic Sunday off-day",
                    "request": {
                        "method": "POST",
                        "path": "/appointments",
                        "headers": alice_headers,
                        "body": {
                            "doctor_id": 101,
                            "appointment_date": "2026-10-11",
                            "slot_id": "SLOT-0900",
                            "patient_phone": "+1234567890",
                        },
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-026",
            "dsl_version": "1.0",
            "title": "BUG-026: Conflict status code must be 409 not 500",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "trigger double booking conflict status check",
                    "request": {
                        "method": "POST",
                        "path": "/appointments",
                        "headers": bob_headers,
                        "body": {
                            "doctor_id": 101,
                            "appointment_date": "2026-10-15",
                            "slot_id": "SLOT-0900",
                            "patient_phone": "+1987654321",
                        },
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-027",
            "dsl_version": "1.0",
            "title": "BUG-027: Reschedule within 24h window constraint",
            "endpoint": {"method": "PATCH", "path": "/appointments/{appointment_id}"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": [
                {
                    "name": "reschedule appointment within 24h",
                    "request": {
                        "method": "PATCH",
                        "path": "/appointments/{appointment_id}",
                        "path_params": {"appointment_id": 502},
                        "headers": bob_headers,
                        "body": {"new_date": "2026-10-19", "new_slot_id": "SLOT-1000"},
                    },
                    "assertions": [{"type": "status", "equals": 409}],
                }
            ],
        },
        {
            "id": "BUG-028",
            "dsl_version": "1.0",
            "title": "BUG-028: Exceeding daily doctor quota constraint",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "BUSINESS_RULE",
            "source": "RULE",
            "steps": quota_steps_28,
        },
        {
            "id": "BUG-029",
            "dsl_version": "1.0",
            "title": "BUG-029: Slot start_time ISO 8601 format check",
            "endpoint": {"method": "GET", "path": "/doctors/{doctor_id}/slots"},
            "technique": "TYPE_MISMATCH",
            "source": "RULE",
            "steps": [
                {
                    "name": "get slots check ISO format",
                    "request": {
                        "method": "GET",
                        "path": "/doctors/{doctor_id}/slots",
                        "path_params": {"doctor_id": 101},
                        "query": {"date": "2026-10-15"},
                    },
                    "assertions": [
                        {"type": "status", "equals": 200},
                        {
                            "type": "json_path",
                            "path": "$[0].start_time",
                            "op": "matches",
                            "value": r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$",
                        },
                    ],
                }
            ],
        },
        {
            "id": "BUG-030",
            "dsl_version": "1.0",
            "title": "BUG-030: Patient cannot cancel other appointment (IDOR)",
            "endpoint": {"method": "DELETE", "path": "/appointments/{appointment_id}"},
            "technique": "AUTH",
            "source": "HYBRID",
            "steps": [
                {
                    "name": "Alice tries to cancel Bob appointment 502",
                    "request": {
                        "method": "DELETE",
                        "path": "/appointments/{appointment_id}",
                        "path_params": {"appointment_id": 502},
                        "headers": alice_headers,
                    },
                    "assertions": [{"type": "status", "equals": 403}],
                }
            ],
        },
        {
            "id": "BUG-031",
            "dsl_version": "1.0",
            "title": "BUG-031: Non-existent department ID status code should be 404",
            "endpoint": {"method": "GET", "path": "/departments/{department_id}"},
            "technique": "INVALID_INPUT",
            "source": "RULE",
            "steps": [
                {
                    "name": "get non-existent department 999",
                    "request": {
                        "method": "GET",
                        "path": "/departments/{department_id}",
                        "path_params": {"department_id": 999},
                    },
                    "assertions": [{"type": "status", "equals": 404}],
                }
            ],
        },
        {
            "id": "BUG-032",
            "dsl_version": "1.0",
            "title": "BUG-032: Invalid phone number format validation",
            "endpoint": {"method": "POST", "path": "/appointments"},
            "technique": "INVALID_INPUT",
            "source": "RULE",
            "steps": [
                {
                    "name": "book with invalid phone format",
                    "request": {
                        "method": "POST",
                        "path": "/appointments",
                        "headers": alice_headers,
                        "body": {
                            "doctor_id": 101,
                            "appointment_date": "2026-10-16",
                            "slot_id": "SLOT-1000",
                            "patient_phone": "invalid-phone",
                        },
                    },
                    "assertions": [{"type": "status", "equals": 422}],
                }
            ],
        },
    ]
