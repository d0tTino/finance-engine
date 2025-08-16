"""Vectorized backtesting helpers.

This module provides utilities for running simple vectorized
backtests over a grid of parameter values.  Strategies are
expected to return a ``pd.Series`` of desired positions.  Slippage
costs are applied whenever the position changes.
"""

from __future__ import annotations

from itertools import product
from typing import Callable, Dict, Iterable, Sequence

import pandas as pd


def _simulate(
    prices: pd.Series,
    positions: pd.Series,
    slippage: float,
) -> pd.Series:
    """Compute per-period returns given prices and positions.

    Parameters
    ----------
    prices:
        Series of prices indexed by time.
    positions:
        Series of target positions for each period.
    slippage:
        Fractional cost applied to position changes.

    Returns
    -------
    pd.Series
        Series of returns for each period.
    """
    prices = prices.astype(float)
    pct_change = prices.pct_change().fillna(0.0)
    trades = positions.diff().abs().fillna(0.0)
    returns = positions.shift().fillna(0.0) * pct_change - slippage * trades
    return returns


def run_backtest(
    strategy: Callable[..., pd.Series],
    data: pd.DataFrame,
    params: Dict[str, Sequence],
    slippage: float = 0.0,
) -> pd.DataFrame:
    """Run a strategy over a grid of parameters.

    Parameters
    ----------
    strategy:
        Callable accepting ``data`` and parameter values and returning a
        Series of positions.
    data:
        Input DataFrame containing at least a ``price`` column.
    params:
        Mapping from parameter name to a sequence of values to search.
    slippage:
        Fractional slippage cost applied to position changes.

    Returns
    -------
    pd.DataFrame
        DataFrame where each row corresponds to a parameter combination
        with an additional ``return`` column containing the cumulative
        return over the run.
    """
    if "price" not in data.columns:
        raise ValueError("data must contain a 'price' column")

    names = list(params)
    grid: Iterable[Sequence] = product(*params.values()) if names else [()]

    results = []
    for combo in grid:
        kwargs = dict(zip(names, combo))
        positions = strategy(data, **kwargs)
        returns = _simulate(data["price"], positions, slippage)
        total_return = (1.0 + returns).prod() - 1.0
        results.append({**kwargs, "return": total_return})

    return pd.DataFrame(results)


def walk_forward(
    strategy: Callable[..., pd.Series],
    data: pd.DataFrame,
    params: Dict[str, Sequence],
    window: int,
    step: int,
    slippage: float = 0.0,
) -> pd.DataFrame:
    """Perform walk-forward evaluation of ``strategy``.

    The data is split into sequential training and testing windows.  For each
    step, the best parameter combination (by cumulative return) is selected on
    the training slice and then evaluated on the subsequent test slice.
    Returns for each evaluation period are compounded to produce a cumulative
    performance column.

    Parameters
    ----------
    strategy:
        Callable accepting ``data`` and parameter values and returning a
        Series of positions.
    data:
        Input DataFrame containing at least a ``price`` column.
    params:
        Mapping from parameter name to a sequence of values to search.
    window:
        Size of the rolling training window.
    step:
        Number of periods in each test slice and the amount to advance the
        window by after each iteration.
    slippage:
        Fractional slippage cost applied to position changes.

    Returns
    -------
    pd.DataFrame
        DataFrame with one row per test window containing the chosen
        parameters, the window's return and cumulative compounded return up to
        that point.
    """

    if "price" not in data.columns:
        raise ValueError("data must contain a 'price' column")

    names = list(params)
    results: list[dict[str, float]] = []
    cumulative = 1.0

    for start in range(window, len(data), step):
        train = data.iloc[start - window:start]
        test = data.iloc[start:start + step]
        if test.empty:
            break

        train_perf = run_backtest(strategy, train, params, slippage)
        if train_perf.empty:
            continue
        best_idx = train_perf["return"].idxmax()
        best_params = {name: train_perf.loc[best_idx, name] for name in names}

        positions = strategy(test, **best_params)
        returns = _simulate(test["price"], positions, slippage)
        window_return = (1.0 + returns).prod() - 1.0
        cumulative *= 1.0 + window_return

        results.append(
            {
                **best_params,
                "start": test.index[0],
                "end": test.index[-1],
                "return": window_return,
                "cumulative": cumulative - 1.0,
            }
        )

    return pd.DataFrame(results)


__all__ = ["run_backtest", "walk_forward"]
