"""Cross-market pairs trading utilities.

This module provides vectorised routines for constructing and backtesting
pairs trading strategies that exploit correlations across related markets.
"""

from __future__ import annotations

from typing import Sequence, Tuple

import numpy as np
import pandas as pd

Pair = Tuple[str, str]


def compute_signals(
    prices: pd.DataFrame,
    pairs: Sequence[Pair],
    lookback: int = 20,
    threshold: float = 1.0,
) -> pd.DataFrame:
    """Return trading signals for each pair of markets.

    Parameters
    ----------
    prices:
        DataFrame where each column represents a market's price series.
    pairs:
        Sequence of column name pairs specifying related markets.
    lookback:
        Number of periods used to compute the rolling z-score of each
        pair's spread. Must be a positive integer.
    threshold:
        Z-score level required to open a position.

    Returns
    -------
    pd.DataFrame
        DataFrame indexed like ``prices`` with a column for each pair.
        Entries take values ``-1`` (short first/long second), ``1`` (long
        first/short second) or ``0`` (no trade).
    """
    if lookback < 1:
        raise ValueError("lookback must be at least 1")

    missing = {c for p in pairs for c in p if c not in prices.columns}
    if missing:
        raise KeyError(f"Missing columns: {missing}")

    log_prices = np.log(prices)
    spreads = pd.DataFrame(
        {f"{a}/{b}": log_prices[a] - log_prices[b] for a, b in pairs},
        index=prices.index,
    )
    mean = spreads.rolling(lookback).mean()
    std = spreads.rolling(lookback).std()
    zscore = (spreads - mean) / std

    signals = -np.sign(zscore)
    signals = signals.where(zscore.abs() > threshold, 0.0)
    return signals.fillna(0.0)


def vectorized_backtest(
    prices: pd.DataFrame,
    pairs: Sequence[Pair],
    lookback: int = 20,
    threshold: float = 1.0,
) -> pd.Series:
    """Run a vectorised backtest of the cross-pairs strategy.

    The strategy enters positions based on :func:`compute_signals` and
    realises profit and loss from the subsequent period's returns.

    Returns
    -------
    pd.Series
        Cumulative profit and loss over time assuming unit capital per pair.
    """
    signals = compute_signals(prices, pairs, lookback, threshold)

    returns = prices.pct_change().shift(-1)
    pair_returns = pd.DataFrame(
        {f"{a}/{b}": returns[a] - returns[b] for a, b in pairs},
        index=prices.index,
    )

    pnl = (signals * pair_returns).sum(axis=1).fillna(0.0)
    return pnl.cumsum()


def grid_search(
    prices: pd.DataFrame,
    pairs: Sequence[Pair],
    lookbacks: Sequence[int],
    thresholds: Sequence[float],
) -> pd.DataFrame:
    """Evaluate strategy performance across parameter combinations.

    Returns a DataFrame whose rows correspond to ``lookbacks`` and columns to
    ``thresholds``. Each entry contains the final cumulative return from the
    backtest.
    """
    results: dict[tuple[int, float], float] = {}
    for lb in lookbacks:
        for th in thresholds:
            pnl = vectorized_backtest(prices, pairs, lb, th).iloc[-1]
            results[(lb, th)] = pnl

    df = pd.Series(results).unstack()
    df.index.name = "lookback"
    df.columns.name = "threshold"
    return df


def shuffled_label_significance(
    prices: pd.DataFrame,
    pairs: Sequence[Pair],
    lookback: int,
    threshold: float,
    n_shuffles: int = 100,
    seed: int | None = None,
) -> tuple[float, np.ndarray, float]:
    """Assess performance significance via column label shuffling.

    Columns of ``prices`` are randomly permuted ``n_shuffles`` times, breaking
    the relationship between the supplied ``pairs`` and the underlying price
    series. The distribution of resulting returns is compared with the actual
    strategy performance to compute a one-sided p-value.

    Returns
    -------
    actual:
        Final cumulative return from the unshuffled data.
    shuffled:
        Array of final returns from each shuffle.
    p_value:
        Proportion of shuffled outcomes greater than or equal to ``actual``.
    """
    rng = np.random.default_rng(seed)
    actual = vectorized_backtest(prices, pairs, lookback, threshold).iloc[-1]

    shuffled = []
    for _ in range(n_shuffles):
        shuffled_prices = prices.copy()
        shuffled_prices.columns = rng.permutation(shuffled_prices.columns)
        pnl = vectorized_backtest(
            shuffled_prices, pairs, lookback, threshold
        ).iloc[-1]
        shuffled.append(pnl)

    shuffled_arr = np.asarray(shuffled)
    p_value = (np.sum(shuffled_arr >= actual) + 1) / (n_shuffles + 1)
    return actual, shuffled_arr, p_value


__all__ = [
    "compute_signals",
    "vectorized_backtest",
    "grid_search",
    "shuffled_label_significance",
]
