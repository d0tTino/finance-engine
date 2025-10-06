import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.cross_pairs import (  # noqa: E402
    compute_signals,
    grid_search,
    shuffled_label_significance,
    vectorized_backtest,
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
    pnl = vectorized_backtest(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)

    assert pnl.index.equals(prices.index)
    signals = compute_signals(prices, PAIR_DEFINITION, lookback=5, threshold=0.8)
    returns = prices.pct_change().shift(-1)
    pair_returns = pd.DataFrame(
        {
            f"{a}/{b}": returns[a] - returns[b]
            for a, b in PAIR_DEFINITION
        },
        index=prices.index,
    )
    expected = (signals * pair_returns).sum(axis=1).fillna(0.0).cumsum()

    pd.testing.assert_series_equal(pnl, expected, check_freq=False)


def test_grid_search_collates_backtest_results():
    prices = make_price_frame()
    lookbacks = [4, 5]
    thresholds = [0.6, 0.8]

    grid = grid_search(prices, PAIR_DEFINITION, lookbacks, thresholds)

    expected = pd.DataFrame(
        {
            0.6: [
                vectorized_backtest(prices, PAIR_DEFINITION, 4, 0.6).iloc[-1],
                vectorized_backtest(prices, PAIR_DEFINITION, 5, 0.6).iloc[-1],
            ],
            0.8: [
                vectorized_backtest(prices, PAIR_DEFINITION, 4, 0.8).iloc[-1],
                vectorized_backtest(prices, PAIR_DEFINITION, 5, 0.8).iloc[-1],
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
        vectorized_backtest(prices, PAIR_DEFINITION, lookback, threshold).iloc[-1]
    )
    assert shuffled.shape == (25,)
    assert np.all(np.isfinite(shuffled))
    assert 0.0 < p_value < 1.0


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
