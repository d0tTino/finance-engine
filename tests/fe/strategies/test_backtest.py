import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.backtest import run_backtest, walk_forward  # noqa: E402


def threshold_strategy(data: pd.DataFrame, cutoff: float) -> pd.Series:
    return (data["price"] > cutoff).astype(float)


def make_prices(n: int = 30) -> pd.DataFrame:
    return pd.DataFrame({"price": np.linspace(1.0, 1.3, n)})


def test_run_backtest_no_nulls():
    data = make_prices()
    params = {"cutoff": [1.05, 1.15]}
    results = run_backtest(
        threshold_strategy, data, params, slippage=0.01, n_shuffles=5, seed=0
    )
    assert set("cutoff return sharpe max_drawdown p_value".split()).issubset(
        results.columns
    )
    assert not results.isna().any().any()


def test_walk_forward_no_nulls():
    data = make_prices(40)
    params = {"cutoff": [1.05, 1.15]}
    results = walk_forward(
        threshold_strategy, data, params, window=20, step=10, slippage=0.0
    )
    assert set("cutoff start end return cumulative".split()).issubset(
        results.columns
    )
    assert not results.isna().any().any()
