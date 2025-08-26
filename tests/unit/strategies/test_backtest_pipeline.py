import sys
from pathlib import Path

import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies import run_backtest, walk_forward  # noqa: E402


def buy_and_hold(_data):
    """Simple strategy that is always long one unit."""
    return pd.Series(1, index=_data.index)


def test_walk_forward_windowing_and_aggregation():
    df = pd.DataFrame({"price": range(1, 11)})
    result = walk_forward(buy_and_hold, df, {}, window=4, step=2)

    # Expect three evaluation windows: [4-5], [6-7], [8-9]
    assert list(result["start"]) == [4, 6, 8]
    assert list(result["end"]) == [5, 7, 9]

    expected_returns = [
        6 / 5 - 1,
        8 / 7 - 1,
        10 / 9 - 1,
    ]
    assert result["return"].tolist() == pytest.approx(
        expected_returns, rel=1e-9
    )

    cumulative = []
    running = 1.0
    for r in expected_returns:
        running *= 1 + r
        cumulative.append(running - 1)
    assert result["cumulative"].tolist() == pytest.approx(cumulative, rel=1e-9)


def test_run_backtest_metrics():
    df = pd.DataFrame({"price": [1.0, 1.2, 1.1, 1.3]})
    result = run_backtest(buy_and_hold, df, {}, n_shuffles=10, seed=0)
    assert {
        "return",
        "sharpe",
        "max_drawdown",
        "p_value",
    }.issubset(result.columns)
    row = result.iloc[0]
    assert row["return"] == pytest.approx(0.3, rel=1e-9)
    assert row["max_drawdown"] <= 0
    assert 0 <= row["p_value"] <= 1
