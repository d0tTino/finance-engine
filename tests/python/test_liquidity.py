import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[2]))

import pandas as pd
from fe.features import compute_liquidity


def test_compute_liquidity_averages_depth_over_window():
    data = [
        {
            "market_id": "m1",
            "timestamp": "2024-01-01T08:00:00Z",
            "bids": [(0.5, 1.0)],
            "asks": [(0.6, 1.0)],
        },
        {
            "market_id": "m1",
            "timestamp": "2024-01-01T10:00:00Z",
            "bids": [(0.5, 10.0), (0.4, 5.0)],
            "asks": [(0.6, 7.0), (0.7, 3.0)],
        },
        {
            "market_id": "m1",
            "timestamp": "2024-01-01T11:00:00Z",
            "bids": [(0.5, 8.0)],
            "asks": [(0.6, 9.0)],
        },
        {
            "market_id": "m2",
            "timestamp": "2024-01-01T10:30:00Z",
            "bids": [(0.3, 4.0)],
            "asks": [(0.4, 6.0), (0.5, 2.0)],
        },
    ]
    df = pd.DataFrame(data)

    result = compute_liquidity(df, hours=2)

    assert set(result.index) == {"m1", "m2"}
    assert result["m1"] == 10.5  # (12.5 + 8.5) / 2
    assert result["m2"] == 6.0


def test_empty_dataframe_returns_empty_series():
    df = pd.DataFrame(columns=["market_id", "timestamp", "bids", "asks"])
    result = compute_liquidity(df)
    assert result.empty
