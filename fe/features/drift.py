"""Calculate rolling price change (drift) for price series."""
from __future__ import annotations

import pandas as pd


def rolling_drift(prices: pd.Series, window: int = 1) -> pd.Series:
    """Return the price change compared to ``window`` periods ago.

    The function is fully vectorised using :meth:`pandas.Series.diff` and
    therefore scales efficiently for large inputs.

    Args:
        prices: Series containing price data.
        window: Number of periods to look back when computing the drift.
            Must be a positive integer.

    Returns:
        A ``pd.Series`` with the same index as ``prices`` containing the
        difference between the current value and the value ``window`` periods
        ago. The first ``window`` entries will be ``NaN`` as insufficient data
        exists to compute the drift.
    """
    if window < 1:
        raise ValueError("window must be at least 1")

    return prices.diff(periods=window)
