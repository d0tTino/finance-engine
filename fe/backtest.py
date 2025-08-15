"""Backtesting utilities including parameter grid search and walk-forward validation."""

from __future__ import annotations

from itertools import product
from typing import Dict, Iterable, List, Type

import numpy as np
import pandas as pd


def parameter_grid(param_grid: Dict[str, Iterable[float]]) -> List[Dict[str, float]]:
    """Return a list of dictionaries for all combinations in ``param_grid``."""

    keys = list(param_grid)
    combos = product(*(param_grid[k] for k in keys))
    return [dict(zip(keys, values)) for values in combos]


def walk_forward_validation(
    df: pd.DataFrame,
    strategy_cls: Type,
    param_grid: Dict[str, Iterable[float]],
    train_size: int,
    test_size: int,
    price_col: str = "no_price",
) -> pd.DataFrame:
    """Perform walk-forward validation for ``strategy_cls`` on ``df``.

    Each step fits the best parameters (by Sharpe ratio) on the training window
    and evaluates them on the subsequent test window.
    """

    results: List[Dict[str, float]] = []
    for start in range(0, len(df) - train_size - test_size + 1, test_size):
        train = df.iloc[start : start + train_size]
        test = df.iloc[start + train_size : start + train_size + test_size]
        best_params: Dict[str, float] | None = None
        best_sharpe = -np.inf
        for params in parameter_grid(param_grid):
            strat = strategy_cls(**params)
            metrics = strat.backtest(train, price_col=price_col, n_shuffle=0)
            if metrics["sharpe"] > best_sharpe:
                best_sharpe = metrics["sharpe"]
                best_params = params
        if best_params is None:
            continue
        strat = strategy_cls(**best_params)
        eval_metrics = strat.backtest(test, price_col=price_col)
        eval_metrics.update(best_params)
        results.append(eval_metrics)
    return pd.DataFrame(results)
