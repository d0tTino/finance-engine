from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pandas as pd
import pytest


def test_wheel_dry_run_and_strategy_load() -> None:
    """Install the built wheel in a dry-run and exercise a strategy."""

    root = Path(__file__).resolve().parents[2]
    dist = root / "dist"
    wheels = list(dist.glob("*.whl"))
    if not wheels:
        pytest.skip("No wheel built to test")
    wheel = wheels[0]

    subprocess.run(
        [
            sys.executable,
            "-m",
            "pip",
            "install",
            "--dry-run",
            "--no-deps",
            str(wheel),
        ],
        check=True,
    )

    sys.path.insert(0, str(wheel))
    from fe.strategies.deadline_no import DeadlineNoStrategy

    df = pd.DataFrame(
        {
            "time_to_deadline": [5, 2, 1, 0],
            "no_price": [0.6, 0.5, 0.4, 0.3],
        }
    )
    strat = DeadlineNoStrategy(entry_days=3, exit_days=1)

    signal = strat.generate_signals(df)
    assert signal.sum() == 1

    metrics = strat.backtest(df, n_shuffle=0)
    assert "pnl" in metrics
