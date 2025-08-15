"""Liquidity feature computation.

This module provides utilities to compute liquidity metrics from
order book snapshots.  The primary entry point is
:func:`compute_liquidity`, which calculates the average depth across
the bid and ask sides of the book over a lookback window.
"""

from __future__ import annotations

from typing import Sequence

import pandas as pd


def _depth(levels: Sequence[Sequence[float]]) -> float:
    """Return total quantity depth for a list of price levels."""
    if levels is None:
        return 0.0
    return float(sum(level[1] for level in levels))


def compute_liquidity(df: pd.DataFrame, hours: int = 1) -> pd.Series:
    """Compute average bid/ask depth for each market.

    Parameters
    ----------
    df:
        DataFrame containing order book snapshots with the following
        columns:
            ``market_id`` – market identifier
            ``timestamp`` – snapshot timestamp
            ``bids`` – iterable of ``[price, quantity]`` pairs for bids
            ``asks`` – iterable of ``[price, quantity]`` pairs for asks
    hours:
        Lookback window in hours.  Only snapshots within the past
        ``hours`` from the most recent timestamp are considered.

    Returns
    -------
    pd.Series
        Series indexed by market ID with the average depth computed as
        ``0.5 * (bid_depth + ask_depth)`` across snapshots in the
        window.
    """
    if df.empty:
        return pd.Series(dtype=float)

    data = df.copy()
    data["timestamp"] = pd.to_datetime(data["timestamp"], utc=True)

    cutoff = data["timestamp"].max() - pd.Timedelta(hours=hours)
    window = data[data["timestamp"] >= cutoff]

    bid_depth = window["bids"].apply(_depth)
    ask_depth = window["asks"].apply(_depth)
    depth = 0.5 * (bid_depth + ask_depth)

    return depth.groupby(window["market_id"]).mean()


__all__ = ["compute_liquidity"]
