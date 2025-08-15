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


def _simulate(prices: pd.Series, positions: pd.Series, slippage: float) -> pd.Series:
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


__all__ = ["run_backtest"]
