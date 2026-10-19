"""Experiment parameters (they match the parameter table of the paper). Every script imports them from here."""
import os

SEED = 2026

# Root of this repository (the AIT platform, its benchmarks and paper/)
AIT_REPO = os.environ.get("AIT_REPO", os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..")))

# Extended experiment protocol
MODELS = ["qwen2.5-coder:7b", "qwen3-coder:30b"]
STRATEGIES = ["RULE", "LLM", "HYBRID"]
BENCHMARKS = ["simple-store", "ecommerce-order", "appointment-booking"]
REPETITIONS = 5
SEEDS = [42, 1024, 2026, 2027, 2028]
TEMPERATURE = 0.2

# Baseline Schemathesis (V2)
SCHEMATHESIS_IMAGE = "schemathesis/schemathesis:v4.28.0"
ST_MAX_EXAMPLES = 100  # Schemathesis default ("out of the box" configuration of the baseline)
ST_PHASES = "examples,coverage,fuzzing,stateful"
ST_CHECKS = "all"

# Statistical tests
ALPHA = 0.05

# List of run folders (model, benchmark -> folder holding experiments.json);
# paths are absolute or relative to AIT_REPO
MANIFEST = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "results", "manifest.json")
