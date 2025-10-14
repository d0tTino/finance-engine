import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.cross_pairs import (  # noqa: E402
    compute_backtest_metrics,
    compute_signals,
    grid_search,
    shuffled_label_significance,
    vectorized_backtest,
    Strategy,
)


PAIR_DEFINITION = [("A", "B"), ("C", "D")]


def make_price_frame() -> pd.DataFrame:
    rng = np.random.default_rng(0)
    idx = pd.date_range("2020-01-01", periods=60, freq="D")
    base = np.cumsum(rng.normal(0.1, 0.05, size=len(idx))) + 100
    spread_one = np.cumsum(rng.normal(0.0, 0.02, size=len(idx)))
    spread_two = np.cumsum(rng.normal(0.0, 0.02, size=len(idx)))

    return pd.DataFrame(
        {
            "A": base + spread_one,
            "B": base - spread_one,
            "C": base * 0.8 + spread_two,
            "D": base * 0.8 - spread_two,
        },
        index=idx,
    )


def test_compute_signals_matches_expected_pattern():
    prices = make_price_frame()
    signals = compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)

    assert list(signals.columns) == ["A/B", "C/D"]
    assert signals.index.equals(prices.index)
    assert not signals.isna().any().any()

    assert signals.loc[pd.Timestamp("2020-01-07"), "A/B"] == -1.0
    assert signals.loc[pd.Timestamp("2020-01-07"), "C/D"] == 1.0
    assert signals.loc[pd.Timestamp("2020-01-10"), "A/B"] == 0.0
    assert signals.loc[pd.Timestamp("2020-02-26"), "C/D"] == 1.0


def test_vectorized_backtest_agrees_with_manual_pnl():
    prices = make_price_frame()
    period_returns, cumulative = vectorized_backtest(
        prices, PAIR_DEFINITION, lookback=5, threshold=0.8
    )

    assert period_returns.index.equals(prices.index)
    assert cumulative.index.equals(prices.index)
    signals = compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)
    price_returns = prices.pct_change().shift(-1)
    pair_returns = pd.DataFrame(
        {
            f"{a}/{b}": price_returns[a] - price_returns[b]
            for a, b in PAIR_DEFINITION
        },
        index=prices.index,
    )
    per_pair = (signals * pair_returns).sum(axis=1).fillna(0.0)
    expected_cumulative = per_pair.cumsum()

    pd.testing.assert_series_equal(period_returns, per_pair, check_freq=False)
    pd.testing.assert_series_equal(cumulative, expected_cumulative, check_freq=False)


def test_vectorized_backtest_applies_slippage():
    prices = make_price_frame()
    returns, _ = vectorized_backtest(
        prices, PAIR_DEFINITION, lookback=5, threshold=0.8, slippage=0.1
    )
    signals = compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)
    pct = prices.pct_change().shift(-1)
    pair_returns = pd.DataFrame(
        {f"{a}/{b}": pct[a] - pct[b] for a, b in PAIR_DEFINITION},
        index=prices.index,
    )
    gross = (signals * pair_returns).sum(axis=1).fillna(0.0)
    trades = signals.diff().abs().sum(axis=1).fillna(0.0)
    expected = gross - 0.1 * trades

    pd.testing.assert_series_equal(returns, expected, check_freq=False)


def test_vectorized_backtest_supports_callable_slippage():
    prices = make_price_frame()

    def slip_model(trades: pd.DataFrame, pair_returns: pd.DataFrame) -> pd.Series:
        base_cost = trades.sum(axis=1)
        spread_cost = pair_returns.abs().sum(axis=1)
        return 0.05 * base_cost + 0.01 * spread_cost

    returns, _ = vectorized_backtest(
        prices,
        PAIR_DEFINITION,
        lookback=5,
        threshold=0.8,
        slippage=slip_model,
    )

    signals = compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)
    pct = prices.pct_change().shift(-1)
    pair_returns = pd.DataFrame(
        {f"{a}/{b}": pct[a] - pct[b] for a, b in PAIR_DEFINITION},
        index=prices.index,
    )
    gross = (signals * pair_returns).sum(axis=1).fillna(0.0)
    expected = gross - slip_model(signals.diff().abs().fillna(0.0), pair_returns)

    pd.testing.assert_series_equal(returns, expected.fillna(0.0), check_freq=False)


def test_grid_search_collates_backtest_results():
    prices = make_price_frame()
    lookbacks = [4, 5]
    thresholds = [0.6, 0.8]

    grid = grid_search(prices, PAIR_DEFINITION, lookbacks, thresholds)

    expected = pd.DataFrame(
        {
            0.6: [
                vectorized_backtest(prices, PAIR_DEFINITION, 4, 0.6)[1].iloc[-1],
                vectorized_backtest(prices, PAIR_DEFINITION, 5, 0.6)[1].iloc[-1],
            ],
            0.8: [
                vectorized_backtest(prices, PAIR_DEFINITION, 4, 0.8)[1].iloc[-1],
                vectorized_backtest(prices, PAIR_DEFINITION, 5, 0.8)[1].iloc[-1],
            ],
        },
        index=pd.Index(lookbacks, name="lookback"),
    )
    expected.columns.name = "threshold"

    pd.testing.assert_frame_equal(grid, expected)


def test_shuffled_label_significance_returns_plausible_distribution():
    prices = make_price_frame()
    lookback = 5
    threshold = 0.8
    actual, shuffled, p_value = shuffled_label_significance(
        prices, PAIR_DEFINITION, lookback, threshold, n_shuffles=25, seed=123
    )

    assert actual == pytest.approx(
        vectorized_backtest(prices, PAIR_DEFINITION, lookback, threshold)[1].iloc[-1]
    )
    assert shuffled.shape == (25,)
    assert np.all(np.isfinite(shuffled))
    assert 0.0 < p_value < 1.0


def test_compute_backtest_metrics_returns_expected_statistics():
    prices = make_price_frame()
    metrics = compute_backtest_metrics(
        prices,
        PAIR_DEFINITION,
        lookback=5,
        threshold=0.8,
        slippage=0.05,
        n_shuffles=5,
        seed=7,
    )

    assert set(metrics) == {
        "returns",
        "cumulative",
        "pnl",
        "sharpe",
        "max_drawdown",
        "hit_rate",
        "turnover",
        "p_value",
    }
    assert isinstance(metrics["returns"], pd.Series)
    assert isinstance(metrics["cumulative"], pd.Series)

    base_returns, base_cumulative = vectorized_backtest(
        prices, PAIR_DEFINITION, lookback=5, threshold=0.8, slippage=0.05
    )
    pd.testing.assert_series_equal(metrics["returns"], base_returns, check_freq=False)
    pd.testing.assert_series_equal(
        metrics["cumulative"], base_cumulative, check_freq=False
    )

    assert np.isfinite(metrics["pnl"])
    assert np.isfinite(metrics["sharpe"])
    assert metrics["max_drawdown"] <= 0.0
    assert 0.0 <= metrics["turnover"]
    assert np.isnan(metrics["hit_rate"]) or 0.0 <= metrics["hit_rate"] <= 1.0
    assert 0.0 < metrics["p_value"] <= 1.0


def test_strategy_backtest_exposes_metrics_dict():
    prices = make_price_frame()
    strat = Strategy(PAIR_DEFINITION, lookback=5, threshold=0.8)

    metrics = strat.backtest(prices, slippage=0.02, n_shuffles=3, seed=99)

    assert isinstance(metrics, dict)
    assert metrics["returns"].index.equals(prices.index)
    assert metrics["cumulative"].index.equals(prices.index)


def test_missing_columns_raise_key_error():
    prices = make_price_frame().drop(columns=["D"])

    with pytest.raises(KeyError, match="Missing columns"):
        compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)


def test_invalid_lookback_raises_value_error():
    prices = make_price_frame()

    with pytest.raises(ValueError, match="lookback must be at least 1"):
        compute_signals(prices, PAIR_DEFINITION, lookback=0, threshold=0.8)

    with pytest.raises(ValueError, match="lookback must be at least 1"):
        vectorized_backtest(prices, PAIR_DEFINITION, lookback=0, threshold=0.8)

    with pytest.raises(ValueError, match="lookback must be at least 1"):
        grid_search(prices, PAIR_DEFINITION, lookbacks=[0], thresholds=[0.5])

    with pytest.raises(ValueError, match="lookback must be at least 1"):
        shuffled_label_significance(
            prices, PAIR_DEFINITION, lookback=0, threshold=0.8, n_shuffles=5
        )
