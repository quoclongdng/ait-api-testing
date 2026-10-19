"""Run Schemathesis on the clean API and on every single-fault variant of ONE benchmark (runs INSIDE the
schemathesis container, on the Docker network of the sandboxes; called by schemathesis_baseline.py).

    python st_driver.py --slug ecommerce-order --bugs BUG-011,BUG-012 --seeds 42,1024 \
        --token customer-alice-token --out /out --max-examples 50 --phases ... --checks all

Each (seed, variant): POST <host>/__admin/reset, then `schemathesis run` with that seed; the JSON report goes
to /out/<slug>/seed-<s>/<variant>.json and the run time to timings.csv.
"""
import argparse
import csv
import os
import subprocess
import time
import urllib.request


def reset(base):
    req = urllib.request.Request(base + "/__admin/reset", method="POST", data=b"")
    err = None
    for _ in range(10):
        try:
            with urllib.request.urlopen(req, timeout=10) as r:
                if r.status < 300:
                    return
        except Exception as exc:  # sandbox not ready yet
            err = exc
        time.sleep(2)
    raise RuntimeError(f"reset failed: {base} ({err})")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug", required=True)
    ap.add_argument("--bugs", required=True, help="BUG-001,BUG-002,...")
    ap.add_argument("--seeds", required=True)
    ap.add_argument("--token", default="")
    ap.add_argument("--schema", default=None, help="default /bench/<slug>/openapi.yaml")
    ap.add_argument("--out", default="/out")
    ap.add_argument("--max-examples", default="50")
    ap.add_argument("--phases", default="examples,coverage,fuzzing,stateful")
    ap.add_argument("--checks", default="all")
    a = ap.parse_args()

    schema = a.schema or f"/bench/{a.slug}/openapi.yaml"
    variants = [("clean", a.slug)] + [(b, f"{a.slug}-{b.lower()}") for b in a.bugs.split(",") if b]
    out_root = os.path.join(a.out, a.slug)
    os.makedirs(out_root, exist_ok=True)
    timing_path = os.path.join(out_root, "timings.csv")
    new = not os.path.exists(timing_path)
    with open(timing_path, "a", newline="", encoding="utf-8") as tf:
        tw = csv.writer(tf)
        if new:
            tw.writerow(["seed", "variant", "host", "exit_code", "seconds"])
        for seed in a.seeds.split(","):
            seed_dir = os.path.join(out_root, f"seed-{seed}")
            os.makedirs(seed_dir, exist_ok=True)
            for variant, host in variants:
                base = f"http://{host}:8000"
                report = os.path.join(seed_dir, f"{variant}.json")
                if os.path.exists(report):
                    continue  # resumable after an interruption
                reset(base)
                cmd = ["schemathesis", "run", schema, "--url", base, "--seed", seed,
                       "--max-examples", a.max_examples, "--phases", a.phases, "--checks", a.checks,
                       "--workers", "1", "--no-color", "--report", "json", "--report-json-path", report,
                       "--generation-database", "none"]
                if a.token:
                    cmd += ["-H", f"Authorization: Bearer {a.token}"]
                t0 = time.time()
                with open(os.path.join(seed_dir, f"{variant}.log"), "w", encoding="utf-8") as log:
                    rc = subprocess.call(cmd, stdout=log, stderr=subprocess.STDOUT)
                tw.writerow([seed, variant, host, rc, round(time.time() - t0, 2)])
                tf.flush()
                print(f"{a.slug} seed={seed} {variant}: exit {rc}, {time.time() - t0:.1f} s", flush=True)


if __name__ == "__main__":
    main()
