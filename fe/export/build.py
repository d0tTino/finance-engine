import argparse
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from fe.signoff.gate import approve


STRATEGIES = ["cross_pairs", "deadline_no", "maker", "rules_alpha"]


def build_wheel(version: str, strategies: list[str]) -> Path:
    """Package selected strategies into a wheel.

    Parameters
    ----------
    version: str
        Semver version string for the wheel.
    strategies: list[str]
        Strategy module names to include.
    """
    repo_root = Path(__file__).resolve().parents[2]

    reports_dir = repo_root / "reports"
    for name in strategies:
        report_path = reports_dir / f"{name}.json"
        if not report_path.is_file():
            raise FileNotFoundError(
                f"Strategy report '{name}' not found at {report_path}"
            )
        report = json.loads(report_path.read_text())
        if not approve(report):
            raise SystemExit(f"Strategy '{name}' failed approval gate")

    dist_dir = repo_root / "dist"
    dist_dir.mkdir(exist_ok=True)

    with tempfile.TemporaryDirectory() as tmpdir:
        pkg_dir = Path(tmpdir) / "polymarket_alpha"
        (pkg_dir / "strategies").mkdir(parents=True)

        # Strategy interface
        (pkg_dir / "__init__.py").write_text(
            """from abc import ABC, abstractmethod

class Strategy(ABC):
    \"\"\"Interface for trading strategies.\"\"\"

    @abstractmethod
    def propose_orders(self, market_state):
        \"\"\"Return proposed orders for the given market state.\"\"\"

    @abstractmethod
    def on_fill(self, fill):
        \"\"\"Handle an order fill event.\"\"\"

    @abstractmethod
    def risk_profile(self):
        \"\"\"Return risk parameters for the strategy.\"\"\"
"""
        )

        # Copy selected strategies
        src_strategies = repo_root / "fe" / "strategies"
        for name in strategies:
            src = src_strategies / f"{name}.py"
            if not src.exists():
                raise FileNotFoundError(f"Strategy '{name}' not found")
            shutil.copy(src, pkg_dir / "strategies" / src.name)

        # Minimal pyproject for packaging
        (Path(tmpdir) / "pyproject.toml").write_text(
            f"""
[build-system]
requires = [\"setuptools>=61\", \"wheel\"]
build-backend = \"setuptools.build_meta\"

[project]
name = \"polymarket_alpha\"
version = \"{version}\"
description = \"Selected strategy implementations for Polymarket Alpha\"
"""
        )

        subprocess.check_call(
            [
                sys.executable,
                "-m",
                "build",
                "--wheel",
                "--outdir",
                str(dist_dir),
            ],
            cwd=tmpdir,
        )

    # Rename the newest generated wheel to the expected filename
    wheels = sorted(
        dist_dir.glob("polymarket_alpha-*.whl"),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )
    if not wheels:
        raise FileNotFoundError("No polymarket_alpha wheel found in dist directory")
    wheel = wheels[0]
    target = dist_dir / f"polymarket_alpha_{version}.whl"
    wheel.rename(target)
    return target


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Build polymarket_alpha wheel"
    )
    parser.add_argument("version", help="Semver version for the wheel")
    parser.add_argument(
        "--strategies",
        nargs="+",
        default=STRATEGIES,
        help="List of strategy module names to include",
    )
    args = parser.parse_args()
    build_wheel(args.version, args.strategies)


if __name__ == "__main__":
    main()
