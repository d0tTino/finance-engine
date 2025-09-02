"""Walk-forward utilities."""

from __future__ import annotations

from typing import Iterator, Sequence, Tuple

import pandas as pd


def walk_forward_splits(
    data: pd.DataFrame,
    window: int,
    step: int,
) -> Iterator[Tuple[pd.DataFrame, pd.DataFrame]]:
    """Yield rolling train/test windows.

    Parameters
    ----------
    data:
        Input dataset.
    window:
        Size of the training window.
    step:
        Size of each test window and the shift between windows.

    Yields
    ------
    tuple[pd.DataFrame, pd.DataFrame]
        The training and testing slices for each step.
    """
    if window <= 0 or step <= 0:
        raise ValueError("window and step must be positive")

    for start in range(window, len(data), step):
        train = data.iloc[start - window:start]
        test = data.iloc[start:start + step]
        if test.empty:
            break
        yield train, test


def aggregate_performance(returns: Sequence[float]) -> pd.DataFrame:
    """Aggregate per-window returns into cumulative performance.

    Parameters
    ----------
    returns:
        Sequence of per-window returns.

    Returns
    -------
    pd.DataFrame
        DataFrame with ``return`` and ``cumulative`` columns where the latter
        contains compounded returns.
    """
    cumulative = 1.0
    rows = []
    for r in returns:
        cumulative *= 1.0 + r
        rows.append({"return": float(r), "cumulative": cumulative - 1.0})
    return pd.DataFrame(rows)


__all__ = ["walk_forward_splits", "aggregate_performance"]
