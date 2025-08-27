import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.backtest import run_backtest, walk_forward  # noqa: E402


def threshold_strategy(data: pd.DataFrame, cutoff: float) -> pd.Series:
    return (data["price"] > cutoff).astype(float)


def make_prices(n: int = 30) -> pd.DataFrame:
    return pd.DataFrame({"price": np.linspace(1.0, 1.3, n)})


def flip_strategy(data: pd.DataFrame) -> pd.Series:
    """Predefined position sequence to expose slippage effects."""
    return pd.Series([0.0, 1.0, 1.0, 0.0], index=data.index)


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


def test_run_backtest_slippage_metrics():
    data = pd.DataFrame({"price": [1.0, 1.1, 1.2, 1.1]})
    with_slip = run_backtest(flip_strategy, data, {}, slippage=0.01)
    no_slip = run_backtest(flip_strategy, data, {}, slippage=0.0)

    assert with_slip.loc[0, "return"] == pytest.approx(-0.0208, rel=1e-6)
    assert with_slip.loc[0, "sharpe"] == pytest.approx(-0.654296, rel=1e-6)
    assert with_slip.loc[0, "max_drawdown"] == pytest.approx(-0.0933333, rel=1e-6)
    assert with_slip.loc[0, "return"] < no_slip.loc[0, "return"]


def test_walk_forward_evaluation():
    prices = pd.Series([1.0, 1.1, 1.2, 1.3, 1.4, 1.3, 1.2, 1.1])
    data = pd.DataFrame({"price": prices})
    params = {"cutoff": [1.05, 1.15]}
    result = walk_forward(
        threshold_strategy, data, params, window=4, step=2, slippage=0.0
    )
    assert result["cutoff"].tolist() == [1.05, 1.05]
    assert result["start"].tolist() == [4, 6]
    assert result["end"].tolist() == [5, 7]
    assert result["return"].tolist() == pytest.approx(
        [-0.0714285714, -0.0833333333], abs=1e-6
    )
    assert result["cumulative"].tolist() == pytest.approx(
        [-0.0714285714, -0.1488095238], abs=1e-6
    )
