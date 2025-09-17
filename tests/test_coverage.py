"""Integration check for feature coverage sample data."""

from __future__ import annotations

import sys
from pathlib import Path
from typing import List

import numpy as np
import pandas as pd

# Ensure the repository root is on ``sys.path`` for local imports.
PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.append(str(PROJECT_ROOT))

from fe.features.coverage import missing_features  # noqa: E402


def _sample_feature_frame(num_markets: int = 100) -> pd.DataFrame:
    """Return a synthetic feature table with sparse missing values."""
    market_ids = [f"m{i:03d}" for i in range(num_markets)]
    data = pd.DataFrame(
        {
            "market_id": market_ids,
            "time_to_deadline": np.linspace(1, 30, num_markets),
            "liquidity": np.linspace(100, 500, num_markets),
            "skew": np.linspace(-0.3, 0.3, num_markets),
            "drift": np.linspace(-0.1, 0.1, num_markets),
            "rule_objectivity": np.linspace(0.5, 0.9, num_markets),
        }
    )

    # Introduce a few missing values per feature column (<5%).
    for offset, column in enumerate(
        [
            "time_to_deadline",
            "liquidity",
            "skew",
            "drift",
            "rule_objectivity",
        ]
    ):
        data.loc[offset: offset + 3, column] = np.nan

    return data


def _write_feature_splits(
    frame: pd.DataFrame, directory: Path, chunks: int = 2
) -> List[str]:
    """Persist the sample frame into multiple parquet files."""
    paths: List[str] = []
    total_rows = len(frame)
    chunk_size = (total_rows + chunks - 1) // chunks
    for idx in range(chunks):
        start = idx * chunk_size
        stop = min(total_rows, (idx + 1) * chunk_size)
        if start >= stop:
            break
        split = frame.iloc[start:stop]
        path = directory / f"sample_features_{idx}.parquet"
        split.to_parquet(path, index=False)
        paths.append(str(path))
    return paths


def test_sample_feature_coverage_below_five_percent(tmp_path: Path) -> None:
    """Load sample feature exports and check that null rates stay below 5%."""
    frame = _sample_feature_frame()
    paths = _write_feature_splits(frame, tmp_path)

    missing = missing_features(paths)
    assert not missing.empty, (
        "missing_features should return feature columns"
    )

    missing_rates = missing.mean()
    failing = missing_rates[missing_rates >= 0.05]
    assert failing.empty, (
        f"Missing ratios above threshold: {failing.to_dict()}"
    )

    # Sanity-check that the sample data intentionally exercises the check.
    assert missing_rates.gt(0).any(), (
        "sample data should include some missing values"
    )
