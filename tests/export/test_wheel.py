from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pytest

pd = pytest.importorskip("pandas")


def test_wheel_install_and_strategy_load(tmp_path: Path) -> None:
    """Install the built wheel into a temporary env and exercise a strategy."""

    root = Path(__file__).resolve().parents[2]
    dist = root / "dist"
    wheels = list(dist.glob("*.whl"))
    if not wheels:
        pytest.skip("No wheel built to test")
    wheel = wheels[0]

    target = tmp_path / "site"
    subprocess.run(
        [
            sys.executable,
            "-m",
            "pip",
            "install",
            "--no-deps",
            "--target",
            str(target),
            str(wheel),
        ],
        check=True,
    )

    sys.path.insert(0, str(target))
    from polymarket_alpha.strategies.deadline_no import DeadlineNoStrategy

    assert (
        DeadlineNoStrategy.__module__
        == "polymarket_alpha.strategies.deadline_no"
    ), "Strategy should be loaded from installed wheel"

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
