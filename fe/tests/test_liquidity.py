"""Tests for :mod:`fe.features.liquidity`."""
from __future__ import annotations

import pandas as pd
import pytest

from fe.features.liquidity import compute_liquidity


def test_compute_liquidity_averages_depth() -> None:
    """Liquidity should average bid/ask depth across snapshots within the window."""

    data = pd.DataFrame(
        [
            {
                "market_id": "m1",
                "timestamp": "2024-01-10T00:00:00Z",
                "bids": [[0.50, 10.0], [0.45, 5.0]],
                "asks": [[0.55, 7.0]],
            },
            {
                "market_id": "m1",
                "timestamp": "2024-01-10T01:00:00Z",
                "bids": [[0.50, 8.0]],
                "asks": [[0.55, 6.0]],
            },
            {
                "market_id": "m1",
                "timestamp": "2024-01-09T20:00:00Z",
                "bids": [[0.50, 20.0]],
                "asks": [[0.55, 10.0]],
            },
            {
                "market_id": "m2",
                "timestamp": "2024-01-10T00:30:00Z",
                "bids": [[0.40, 2.0]],
                "asks": [[0.60, 3.0]],
            },
        ]
    )

    result = compute_liquidity(data, hours=2)

    assert set(result.index) == {"m1", "m2"}
    assert result.loc["m1"] == pytest.approx(9.0)
    assert result.loc["m2"] == pytest.approx(2.5)


def test_compute_liquidity_empty_input() -> None:
    """An empty DataFrame should return an empty Series."""

    result = compute_liquidity(pd.DataFrame())
    assert result.empty
