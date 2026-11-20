#!/usr/bin/env python3
"""Run isolated checks without WordPress bootstrapping, network calls or site writes.

Use --wordpress-core /path/to/wordpress for additional core API fixture checks.
Site integration scripts remain opt-in and require their named disposable peers.
"""
import argparse
import ast
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PHP_FIXTURES = """
activation-missing activation-redirect ajax-diagnostics change-backups
commit-maintenance comparison-cleanup component-comparison component-versions controller-session
deferred-cleanup diagnostic-report directory-replacement force-retransfer
framed-package installed-verification loader-maintenance loader-refresh
performance progress-activity recent-records replacement-copy replacement-progress runtime-lock
setup-hash-reuse site-names source-budget source-symlinks symlink-inventory
timeline-estimates transfer-checkpoints transfer-fast-path transfer-settings
ui-presets update-access verification-progress
""".split()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wordpress-core", type=Path)
    args = parser.parse_args()
    commands = [["node", str(p.relative_to(ROOT))] for p in sorted((ROOT / "tests").glob("*.js"))]
    commands += [["php", "tests/" + name + ".php"] for name in PHP_FIXTURES]
    if args.wordpress_core:
        core = args.wordpress_core.resolve()
        if not (core / "wp-includes/pluggable.php").is_file():
            parser.error("--wordpress-core must contain WordPress core files; wp-config is never loaded")
        commands.append(["php", "tests/controller-cookies.php", str(core)])
        commands += [["php", "tests/security-core.php", str(core), mode]
                     for mode in ["admin", "ajax", "anonymous", "invalid", "bad-nonce"]]
    failures = []
    for command in commands:
        label = " ".join(command)
        try:
            result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=60)
            passed = result.returncode == 0
            output = result.stdout + result.stderr
        except (OSError, subprocess.TimeoutExpired) as error:
            passed, output = False, str(error)
        print(("PASS " if passed else "FAIL ") + label, flush=True)
        if not passed:
            failures.append(label)
            print(output[-12000:], flush=True)
    for path in sorted((ROOT / "tests").glob("*.py")):
        ast.parse(path.read_text(), filename=str(path))
    print(f"{len(commands) - len(failures)}/{len(commands)} isolated checks passed; Python syntax checked.")
    return bool(failures)


if __name__ == "__main__":
    sys.exit(main())
