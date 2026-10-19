"""Tests for DslFingerprintService complying with RFC 8785 and cross-tested with PHP S3-02."""

import json
import struct
from pathlib import Path

import pytest

from app.services.fingerprint import DslFingerprintService
from tests.repo_files import REPO_ROOT


@pytest.fixture
def service() -> DslFingerprintService:
    return DslFingerprintService()


def test_shared_fixture_matches_expected_canonical_json_and_sha256(
    service: DslFingerprintService,
) -> None:
    """Cross-test with PHP S3-02 using the shared test vector."""
    fixture_path = REPO_ROOT / "docs" / "contracts" / "examples" / "canonical-test-vector.json"
    assert fixture_path.exists(), f"Fixture file not found: {fixture_path}"

    with open(fixture_path, encoding="utf-8") as f:
        vector = json.load(f)

    canonical_json = service.to_canonical_json(vector["dsl"])
    assert canonical_json == vector["expected_canonical_json"]

    sha256_hash = service.calculate(vector["dsl"])
    assert sha256_hash == vector["expected_sha256"]


def test_fixed_canonical_json_and_sha256_output(service: DslFingerprintService) -> None:
    dsl = {
        "title": "Ignored title",
        "description": "Ignored description",
        "tags": ["ignored", "tags"],
        "endpoint": {"path": "/items", "method": "GET"},
        "dsl_version": "1.0",
        "source": "RULE",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "assertions": [{"equals": 200, "type": "status"}],
                "name": "get items",
                "request": {
                    "headers": {"Accept": "application/json"},
                    "method": "GET",
                    "path": "/items",
                },
            }
        ],
    }

    expected_json = (
        '{"dsl_version":"1.0","endpoint":{"method":"GET","path":"/items"},"source":"RULE",'
        '"steps":[{"assertions":[{"equals":200,"type":"status"}],"name":"get items",'
        '"request":{"headers":{"Accept":"application/json"},"method":"GET","path":"/items"}}],'
        '"technique":"HAPPY_PATH"}'
    )

    canonical = service.to_canonical_json(dsl)
    assert canonical == expected_json
    assert service.calculate(dsl) == service.calculate(expected_json)


def test_ignored_root_keys_do_not_strip_nested_payload_keys(service: DslFingerprintService) -> None:
    """Keys like 'title' and 'description' are ignored ONLY at the root level."""
    dsl1 = {
        "title": "Case A",
        "description": "Desc A",
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/products"},
        "source": "RULE",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "create",
                "request": {
                    "method": "POST",
                    "path": "/products",
                    "body": {
                        "title": "Product Title",
                        "description": "Product Description",
                    },
                },
                "assertions": [{"type": "status", "equals": 201}],
            }
        ],
    }

    dsl2 = {
        "title": "Case B Different Title",
        "description": "Desc B Different Desc",
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/products"},
        "source": "RULE",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "create",
                "request": {
                    "method": "POST",
                    "path": "/products",
                    "body": {
                        "title": "Product Title",
                        "description": "Product Description",
                    },
                },
                "assertions": [{"type": "status", "equals": 201}],
            }
        ],
    }

    # Different root titles generate the exact same fingerprint
    assert service.calculate(dsl1) == service.calculate(dsl2)

    # But nested 'title' must be present in canonical output
    canonical = service.to_canonical_json(dsl1)
    assert '"title":"Product Title"' in canonical
    assert '"description":"Product Description"' in canonical


def test_nested_payload_difference_changes_fingerprint(service: DslFingerprintService) -> None:
    dsl1 = {
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/items"},
        "steps": [
            {"name": "s1", "request": {"method": "GET", "path": "/items", "query": {"q": "shoes"}}}
        ],
    }
    dsl2 = {
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/items"},
        "steps": [
            {"name": "s1", "request": {"method": "GET", "path": "/items", "query": {"q": "shirts"}}}
        ],
    }

    assert service.calculate(dsl1) != service.calculate(dsl2)


def test_unicode_property_ordering_follows_utf16_code_units(service: DslFingerprintService) -> None:
    """Astral plane character U+10000 (high surrogate 0xD800) must sort before U+FFFF (BMP)."""
    dsl = {
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/unicode"},
        "steps": [
            {
                "name": "unicode sort",
                "request": {
                    "method": "POST",
                    "path": "/unicode",
                    "body": {
                        "\uffff": "bmp_last",
                        "\U00010000": "astral_first",
                    },
                },
            }
        ],
    }

    canonical = service.to_canonical_json(dsl)
    pos_astral = canonical.find("\U00010000")
    pos_bmp = canonical.find("\uffff")

    assert pos_astral != -1
    assert pos_bmp != -1
    assert pos_astral < pos_bmp, (
        "Astral plane key must sort before BMP key in UTF-16 code unit order."
    )


def test_full_rfc_8785_appendix_b_vectors(service: DslFingerprintService) -> None:
    """Exhaustive validation against all 24 IEEE 754 test vectors in RFC 8785 Appendix B."""
    vectors = {
        "0000000000000000": "0",
        "8000000000000000": "0",
        "0000000000000001": "5e-324",
        "8000000000000001": "-5e-324",
        "7fefffffffffffff": "1.7976931348623157e+308",
        "ffefffffffffffff": "-1.7976931348623157e+308",
        "4340000000000000": "9007199254740992",
        "c340000000000000": "-9007199254740992",
        "4430000000000000": "295147905179352830000",
        "44b52d02c7e14af5": "9.999999999999997e+22",
        "44b52d02c7e14af6": "1e+23",
        "44b52d02c7e14af7": "1.0000000000000001e+23",
        "444b1ae4d6e2ef4e": "999999999999999700000",
        "444b1ae4d6e2ef4f": "999999999999999900000",
        "444b1ae4d6e2ef50": "1e+21",
        "3eb0c6f7a0b5ed8c": "9.999999999999997e-7",
        "3eb0c6f7a0b5ed8d": "0.000001",
        "41b3de4355555553": "333333333.3333332",
        "41b3de4355555554": "333333333.33333325",
        "41b3de4355555555": "333333333.3333333",
        "41b3de4355555556": "333333333.3333334",
        "41b3de4355555557": "333333333.33333343",
        "becbf647612f3696": "-0.0000033333333333333333",
        "43143ff3c1cb0959": "1424953923781206.2",
    }

    for hex_val, expected in vectors.items():
        raw_bytes = bytes.fromhex(hex_val)
        double_val = struct.unpack(">d", raw_bytes)[0]
        formatted = service.format_number(double_val)
        assert formatted == expected, (
            f"Failed formatting RFC 8785 vector {hex_val}: expected {expected}, got {formatted}"
        )


def test_nan_and_infinity_throw_value_error(service: DslFingerprintService) -> None:
    with pytest.raises(ValueError, match="Non-finite numbers"):
        service.format_number(float("nan"))

    with pytest.raises(ValueError, match="Non-finite numbers"):
        service.format_number(float("inf"))

    with pytest.raises(ValueError, match="Non-finite numbers"):
        service.format_number(float("-inf"))


def test_string_with_u2028_is_serialized_unescaped(service: DslFingerprintService) -> None:
    u2028 = "\u2028"
    input_str = f"line1{u2028}line2"

    serialized = service.serialize_string(input_str)
    assert serialized == f'"line1{u2028}line2"'
    assert "\\u2028" not in serialized


def test_empty_dsl_throws_value_error(service: DslFingerprintService) -> None:
    with pytest.raises(ValueError, match="empty"):
        service.calculate({})

    with pytest.raises(ValueError, match="empty"):
        service.calculate([])


def test_malformed_json_string_throws_value_error(service: DslFingerprintService) -> None:
    with pytest.raises(ValueError, match="Malformed JSON"):
        service.calculate("{malformed: json")


def test_all_contract_valid_examples_produce_valid_fingerprints_and_are_title_invariant(
    service: DslFingerprintService,
) -> None:
    examples_dir = REPO_ROOT / "docs" / "contracts" / "examples"
    valid_files = list(examples_dir.glob("valid-*.json"))
    assert len(valid_files) >= 5, f"Expected at least 5 valid fixtures, found {len(valid_files)}"

    for file_path in valid_files:
        with open(file_path, encoding="utf-8") as f:
            dsl = json.load(f)

        fp1 = service.calculate(dsl)
        assert service.is_valid_fingerprint(fp1), f"Invalid fingerprint for {file_path.name}"

        # Mutate root metadata (title, description, tags)
        mutated_dsl = json.loads(json.dumps(dsl))
        mutated_dsl["title"] = "Completely Altered Title"
        mutated_dsl["description"] = "Completely Altered Description"
        mutated_dsl["tags"] = ["completely", "new", "tags"]
        fp2 = service.calculate(mutated_dsl)

        assert fp1 == fp2, f"Fingerprint mismatch after mutating title in {file_path.name}"


def test_is_valid_fingerprint(service: DslFingerprintService) -> None:
    valid = "1e4714094aae96c5dd013859be2cbdc8e2cb34370b6ac46024e1327fa74a2c2f"
    assert service.is_valid_fingerprint(valid) is True

    assert service.is_valid_fingerprint(None) is False
    assert service.is_valid_fingerprint("") is False
    assert service.is_valid_fingerprint("short") is False
    assert service.is_valid_fingerprint(valid.upper()) is False  # Must be lowercase hex
    assert service.is_valid_fingerprint(valid[:-1] + "g") is False  # 'g' is not hex


def test_ordinary_decimals_formatting_and_non_collision(service: DslFingerprintService) -> None:
    """Verify that leading zeros in decimals are properly handled without mutating values."""
    assert service.format_number(0.2) == "0.2"
    assert service.format_number(0.25) == "0.25"
    assert service.format_number(0.01) == "0.01"
    assert service.format_number(0.001) == "0.001"
    assert service.format_number(2.5) == "2.5"
    assert service.format_number(100.0) == "100"
    assert service.format_number(-0.25) == "-0.25"

    base_dsl = {
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/orders"},
        "source": "RULE",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "create",
                "request": {
                    "method": "POST",
                    "path": "/orders",
                    "body": {"price": 0.25},
                },
            }
        ],
    }

    # price = 0.25 vs price = 2.5 must NOT collide
    dsl_025 = json.loads(json.dumps(base_dsl))
    dsl_25 = json.loads(json.dumps(base_dsl))
    dsl_25["steps"][0]["request"]["body"]["price"] = 2.5

    fp_025 = service.calculate(dsl_025)
    fp_25 = service.calculate(dsl_25)
    assert fp_025 != fp_25, "0.25 and 2.5 must produce distinct fingerprints"

    # price = 0.2 vs price = 2 must NOT collide
    dsl_02 = json.loads(json.dumps(base_dsl))
    dsl_02["steps"][0]["request"]["body"]["price"] = 0.2
    dsl_2 = json.loads(json.dumps(base_dsl))
    dsl_2["steps"][0]["request"]["body"]["price"] = 2.0

    assert service.calculate(dsl_02) != service.calculate(dsl_2)

    # tax = 0.01 vs tax = 1 must NOT collide
    dsl_001 = json.loads(json.dumps(base_dsl))
    dsl_001["steps"][0]["request"]["body"]["tax"] = 0.01
    dsl_1 = json.loads(json.dumps(base_dsl))
    dsl_1["steps"][0]["request"]["body"]["tax"] = 1.0

    assert service.calculate(dsl_001) != service.calculate(dsl_1)


def test_cross_php_python_decimal_fingerprint_match(service: DslFingerprintService) -> None:
    """Ensure PHP and Python services produce identical SHA-256 for decimals."""
    data = {
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/orders"},
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {
                    "body": {"price": 0.25, "quantity": 2.5, "rate": 0.2, "tax": 0.01},
                    "method": "POST",
                    "path": "/orders",
                },
            }
        ],
        "technique": "HAPPY_PATH",
    }

    expected_canonical = (
        '{"dsl_version":"1.0","endpoint":{"method":"POST","path":"/orders"},"source":"RULE",'
        '"steps":[{"name":"step 1","request":{"body":{'
        '"price":0.25,"quantity":2.5,"rate":0.2,"tax":0.01'
        '},"method":"POST","path":"/orders"}}],"technique":"HAPPY_PATH"}'
    )
    expected_sha256 = "7383f6f6686cffb0ed6073ca36402e737f291f7e58eeac2a789172410f8a48e7"

    assert service.to_canonical_json(data) == expected_canonical
    assert service.calculate(data) == expected_sha256


def test_cross_php_python_large_integers_and_scientific_notation(
    service: DslFingerprintService,
) -> None:
    """Ensure large integers and scientific notation yield identical canonical JSON & SHA-256."""
    # 10^21 integer vs 1e21 exponential
    json_int_21 = '{"n":1000000000000000000000}'
    json_exp_21 = '{"n":1e21}'
    expected_canonical_21 = '{"n":1e+21}'
    expected_sha_21 = "f1ee2b60ee95a3170fdc07a577e5f3514ced26867443d69da265acadead81007"

    assert service.to_canonical_json(json_int_21) == expected_canonical_21
    assert service.to_canonical_json(json_exp_21) == expected_canonical_21
    assert service.calculate(json_int_21) == expected_sha_21
    assert service.calculate(json_exp_21) == expected_sha_21
    assert service.calculate(json_int_21) == service.calculate(json_exp_21)

    # Negative 10^21 vs -1e21
    json_neg_int_21 = '{"n":-1000000000000000000000}'
    json_neg_exp_21 = '{"n":-1e21}'
    expected_neg_canonical_21 = '{"n":-1e+21}'
    expected_neg_sha_21 = "fd33685f4e2a11dbebb3fd9717ea644934e9a9d419b4791871fd2b5324e99ad5"

    assert service.to_canonical_json(json_neg_int_21) == expected_neg_canonical_21
    assert service.to_canonical_json(json_neg_exp_21) == expected_neg_canonical_21
    assert service.calculate(json_neg_int_21) == expected_neg_sha_21
    assert service.calculate(json_neg_exp_21) == expected_neg_sha_21

    # 10^19 integer vs 1e19 exponential (both format as 20-digit integer representation)
    json_int_19 = '{"n":10000000000000000000}'
    json_exp_19 = '{"n":1e19}'
    expected_canonical_19 = '{"n":10000000000000000000}'
    expected_sha_19 = "bdd2b3f22a270bbaf7988ff94df4fc9cccfbc615edf5e58a4f16b484344c04b2"

    assert service.to_canonical_json(json_int_19) == expected_canonical_19
    assert service.to_canonical_json(json_exp_19) == expected_canonical_19
    assert service.calculate(json_int_19) == expected_sha_19
    assert service.calculate(json_exp_19) == expected_sha_19

    # 10^20 integer vs 1e20 exponential (both format as 21-digit integer representation)
    json_int_20 = '{"n":100000000000000000000}'
    json_exp_20 = '{"n":1e20}'
    expected_canonical_20 = '{"n":100000000000000000000}'
    expected_sha_20 = "58d2d5b8dd4228ab5775ce84f996718fa19ed49872271e9649b70cebaca26a09"

    assert service.to_canonical_json(json_int_20) == expected_canonical_20
    assert service.to_canonical_json(json_exp_20) == expected_canonical_20
    assert service.calculate(json_int_20) == expected_sha_20
    assert service.calculate(json_exp_20) == expected_sha_20

    # 64-bit integer limits and IEEE-754 precision:
    # 9007199254740993 (2^53 + 1) rounds to 9007199254740992 per IEEE-754 / RFC 8785
    assert service.to_canonical_json('{"n":9007199254740993}') == '{"n":9007199254740992}'
    assert service.calculate('{"n":9007199254740993}') == service.calculate(
        '{"n":9007199254740992}'
    )

    # PHP_INT_MAX (9223372036854775807) and PHP_INT_MAX + 1 round to 9223372036854776000
    assert service.to_canonical_json('{"n":9223372036854775807}') == '{"n":9223372036854776000}'
    assert (
        service.calculate('{"n":9223372036854775807}')
        == "b92d37d4ecb9f2e1e60ea9038d37f1b47ecd3cfe621d11ccfd8ce393791e397f"
    )

    assert service.to_canonical_json('{"n":9223372036854775808}') == '{"n":9223372036854776000}'
    assert (
        service.calculate('{"n":9223372036854775808}')
        == "b92d37d4ecb9f2e1e60ea9038d37f1b47ecd3cfe621d11ccfd8ce393791e397f"
    )

    # PHP_INT_MIN (-9223372036854775808) and PHP_INT_MIN - 1 round to -9223372036854776000
    assert service.to_canonical_json('{"n":-9223372036854775808}') == '{"n":-9223372036854776000}'
    assert (
        service.calculate('{"n":-9223372036854775808}')
        == "b31010fe1ebb9b50a85ee035f9710653a7a7b86ceb415b3d271be366e10fce13"
    )

    assert service.to_canonical_json('{"n":-9223372036854775809}') == '{"n":-9223372036854776000}'
    assert (
        service.calculate('{"n":-9223372036854775809}')
        == "b31010fe1ebb9b50a85ee035f9710653a7a7b86ceb415b3d271be366e10fce13"
    )


PHP_PROBE = Path(__file__).parent / "fixtures" / "reference_fingerprint.php"
WEB_FINGERPRINT_SERVICE = (
    REPO_ROOT / "web" / "app" / "Services" / "TestEngine" / "TestFingerprintService.php"
)


def php_cross_runtime_prerequisites(
    php_bin: str | None, web_service: Path, required: bool
) -> tuple[str, Path]:
    """Return (php, web class path) or skip/fail when the live PHP cross test cannot run.

    Regression (S10-01 audit r2): the test hard-asserted a php binary, so `make test-ai`
    (python:3.12-slim image, no PHP, no web/ mount) was always red. The hard requirement now
    only applies when REQUIRE_PHP_CROSS_TEST=1, which CI sets.
    """
    missing: list[str] = []
    if php_bin is None:
        missing.append("php binary")
    if not web_service.is_file():
        missing.append(f"web class {web_service}")
    if missing:
        message = "live PHP cross-runtime test needs " + ", ".join(missing)
        if required:
            pytest.fail(message + " (REQUIRE_PHP_CROSS_TEST=1)")
        pytest.skip(message)
    assert php_bin is not None
    return php_bin, web_service


def test_cross_runtime_prerequisites_skip_without_php_when_not_required(
    tmp_path: Path,
) -> None:
    with pytest.raises(pytest.skip.Exception):
        php_cross_runtime_prerequisites(None, tmp_path / "missing.php", required=False)


def test_cross_runtime_prerequisites_fail_without_php_when_required(tmp_path: Path) -> None:
    with pytest.raises(pytest.fail.Exception):
        php_cross_runtime_prerequisites(None, tmp_path / "missing.php", required=True)


def test_cross_runtime_prerequisites_return_paths_when_available(tmp_path: Path) -> None:
    service_file = tmp_path / "TestFingerprintService.php"
    service_file.write_text("<?php\n", encoding="utf-8")

    assert php_cross_runtime_prerequisites("/usr/bin/php", service_file, required=True) == (
        "/usr/bin/php",
        service_file,
    )


def test_php_probe_loads_the_real_web_class_instead_of_a_copy() -> None:
    """Regression (S10-01 audit r2): the probe was a drifting hand-copied PHP class."""
    probe = PHP_PROBE.read_text(encoding="utf-8")

    assert "class TestFingerprintService" not in probe
    assert "require_once $servicePath" in probe
    assert "web/app/Services/TestEngine/TestFingerprintService.php" in probe


def test_live_php_runtime_equivalence_on_large_integers(
    service: DslFingerprintService,
) -> None:
    """Validate live cross-runtime equivalence with the real web PHP implementation."""
    import os
    import shutil
    import subprocess

    php_bin, web_service = php_cross_runtime_prerequisites(
        shutil.which("php"),
        WEB_FINGERPRINT_SERVICE,
        required=os.environ.get("REQUIRE_PHP_CROSS_TEST") == "1",
    )

    test_inputs = [
        '{"n":1000000000000000000000}',
        '{"n":1e21}',
        '{"n":-1000000000000000000000}',
        '{"n":-1e21}',
        '{"n":9223372036854775807}',
        '{"n":9223372036854775808}',
        '{"n":-9223372036854775808}',
        '{"n":-9223372036854775809}',
        '{"n":10000000000000000000}',
        '{"n":1e19}',
        '{"n":100000000000000000000}',
        '{"n":1e20}',
    ]

    res = subprocess.run(  # noqa: S603
        [php_bin, str(PHP_PROBE)],
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, "AIT_WEB_FINGERPRINT_SERVICE": str(web_service)},
    )
    php_lines: dict[str, tuple[str, str]] = {}
    for line in res.stdout.strip().splitlines():
        if " raw=" in line:
            raw_part = line.split(" raw=")[1].split(" canonical=")[0]
            canonical_part = line.split(" canonical=")[1].split(" sha=")[0]
            sha_part = line.split(" sha=")[1].strip()
            php_lines[raw_part] = (canonical_part, sha_part)

    for raw in test_inputs:
        assert raw in php_lines
        php_canonical, php_sha = php_lines[raw]
        py_canonical = service.to_canonical_json(raw)
        py_sha = service.calculate(raw)
        assert py_canonical == php_canonical
        assert py_sha == php_sha
