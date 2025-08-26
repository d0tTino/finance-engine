import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[2]))

import pandas as pd  # noqa: E402
import pytest  # noqa: E402

from fe.strategies import run_backtest  # noqa: E402


def buy_and_hold(_data):
    return pd.Series(1, index=_data.index)


def threshold_strategy(data, threshold):
    returns = data["price"].pct_change().fillna(0.0)
    return (returns > threshold).astype(int)


def test_run_backtest_buy_and_hold():
    df = pd.DataFrame({"price": [1.0, 1.1, 1.2]})
    result = run_backtest(buy_and_hold, df, {}, n_shuffles=10, seed=0)
    assert result.shape[0] == 1
    row = result.iloc[0]
    assert row["return"] == pytest.approx(0.2, rel=1e-9)
    assert {"sharpe", "max_drawdown", "p_value"}.issubset(result.columns)
    assert row["max_drawdown"] == pytest.approx(0.0, abs=1e-12)
    assert 0 <= row["p_value"] <= 1


def test_slippage_and_grid_search():
    df = pd.DataFrame({"price": [1.0, 1.2, 1.1, 1.3]})
    params = {"threshold": [0.0, 0.05]}
    grid = run_backtest(threshold_strategy, df, params, slippage=0.0)
    assert set(grid["threshold"]) == {0.0, 0.05}

    no_slip = run_backtest(
        threshold_strategy, df, {"threshold": [0.0]}, slippage=0.0
    )
    with_slip = run_backtest(
        threshold_strategy, df, {"threshold": [0.0]}, slippage=0.01
    )
    assert with_slip["return"].iloc[0] < no_slip["return"].iloc[0]
