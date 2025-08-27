import pathlib
import sys

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

import pandas as pd  # noqa: E402
from fe.features.liquidity import compute_liquidity  # noqa: E402
from pytest import approx  # noqa: E402


def test_compute_liquidity_basic():
    df = pd.DataFrame(
        {
            "market_id": [1, 1, 2, 2],
            "timestamp": [
                "2023-01-01T00:00:00Z",
                "2023-01-01T00:30:00Z",
                "2023-01-01T00:15:00Z",
                "2022-12-31T22:00:00Z",
            ],
            "bids": [
                [[0.9, 10], [0.8, 5]],
                None,
                [[0.9, 8]],
                [[0.8, 4]],
            ],
            "asks": [
                [[1.1, 7]],
                [[1.2, 4]],
                None,
                [[1.0, 1]],
            ],
        }
    )

    result = compute_liquidity(df, hours=1)
    assert set(result.index) == {1, 2}
    assert result.loc[1] == approx(6.5)
    assert result.loc[2] == approx(4.0)


def test_compute_liquidity_empty():
    df = pd.DataFrame(columns=["market_id", "timestamp", "bids", "asks"])
    result = compute_liquidity(df)
    assert result.empty
