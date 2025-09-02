"""Feature coverage utilities.

Load feature parquet outputs and report missing feature fields per market."""

from __future__ import annotations

from typing import Iterable

import pandas as pd


def missing_features(paths: Iterable[str]) -> pd.DataFrame:
    """Return a boolean coverage matrix for feature data.

    Parameters
    ----------
    paths:
        Iterable of parquet file paths with a ``market_id`` column and
        feature columns.

    Returns
    -------
    pd.DataFrame
        DataFrame indexed by market ID where each column corresponds to a
        feature.  ``True`` indicates the feature value is missing for the
        market.  If no paths are provided, an empty DataFrame is returned.
    """
    frames = [pd.read_parquet(p) for p in paths]
    if not frames:
        return pd.DataFrame()

    df = pd.concat(frames, ignore_index=True)
    if "market_id" not in df.columns:
        raise ValueError("feature data must contain a 'market_id' column")

    missing = df.set_index("market_id").isna()
    if missing.index.has_duplicates:
        missing = missing.groupby(level=0).any()
    return missing


__all__ = ["missing_features"]
