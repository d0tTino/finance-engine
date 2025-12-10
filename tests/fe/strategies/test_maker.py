import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.maker import Strategy, make_quote, pnl_metrics, run_backtest  # noqa: E402


def test_extreme_liquidity_quote_is_not_inverted():
    quote = make_quote(
        yes=0.4,
        no=0.6,
        liquidity=1e6,
        skew=0.0,
        spread=0.02,
        liq_weight=0.5,
        skew_weight=0.5,
    )

    assert quote.bid <= quote.ask
    assert 0.0 <= quote.bid <= 1.0
    assert 0.0 <= quote.ask <= 1.0


def _sample_backtest_frame() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "yes": [0.45, 0.48, 0.44, 0.5, 0.47],
            "no": [0.55, 0.52, 0.56, 0.5, 0.53],
            "liquidity": [0.2, 0.8, 0.4, 0.1, 0.6],
            "skew": [0.0, 0.1, -0.2, 0.05, -0.05],
        }
    )


def test_run_backtest_returns_slippage_adjusted_pnl_series():
    df = _sample_backtest_frame()

    no_slip = run_backtest(
        df, spread=0.02, liq_weight=0.5, skew_weight=0.5, slippage=0.0
    )
    slip = run_backtest(
        df, spread=0.02, liq_weight=0.5, skew_weight=0.5, slippage=0.01
    )

    assert "pnl" in no_slip
    assert "turnover" in no_slip
    assert len(no_slip) == len(df)
    assert np.all(no_slip["pnl"] >= slip["pnl"])


def test_pnl_metrics_computes_expected_statistics():
    pnl = pd.Series([0.1, -0.05, 0.2, -0.1])

    metrics = pnl_metrics(pnl, n_shuffle=10, seed=1, trading_periods=4)

    expected_pnl = float(pnl.sum())
    expected_sharpe = float(pnl.mean() / pnl.std(ddof=0) * np.sqrt(4))
    equity = pnl.cumsum()
    expected_drawdown = float((equity - equity.cummax()).min())
    expected_hit_rate = float((pnl > 0).mean())
    expected_turnover = float(pnl.abs().sum())
    expected_sample_size = len(pnl)

    rng = np.random.default_rng(1)
    shuffled = [rng.permutation(pnl.to_numpy()).sum() for _ in range(10)]
    expected_p_value = float((np.sum(np.array(shuffled) >= expected_pnl) + 1) / 11)

    assert metrics["pnl"] == pytest.approx(expected_pnl)
    assert metrics["sharpe"] == pytest.approx(expected_sharpe)
    assert metrics["max_drawdown"] == pytest.approx(expected_drawdown)
    assert metrics["hit_rate"] == pytest.approx(expected_hit_rate)
    assert metrics["turnover"] == pytest.approx(expected_turnover)
    assert metrics["p_value"] == pytest.approx(expected_p_value)
    assert metrics["sample_size"] == expected_sample_size


def test_strategy_backtest_returns_metrics_and_series():
    df = _sample_backtest_frame()
    strat = Strategy(spread=0.02, liq_weight=0.4, skew_weight=0.6, slippage=0.005)

    metrics = strat.backtest(df, n_shuffle=5, seed=123)

    expected = run_backtest(
        df, spread=0.02, liq_weight=0.4, skew_weight=0.6, slippage=0.005
    )

    assert set(
        [
            "pnl",
            "sharpe",
            "max_drawdown",
            "hit_rate",
            "turnover",
            "p_value",
            "sample_size",
            "pnl_series",
        ]
    ).issubset(metrics)
    assert metrics["pnl_series"].equals(expected["pnl"])
    assert isinstance(metrics["p_value"], float)
    assert 0.0 <= metrics["p_value"] <= 1.0
    assert metrics["sample_size"] == len(expected)
