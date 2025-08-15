import pathlib
import sys

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

import pandas as pd  # noqa: E402
from fe.features.drift import rolling_drift  # noqa: E402


def test_drift_upward():
    prices = pd.Series([1, 2, 3, 4, 5])
    expected = pd.Series([float("nan"), float("nan"), 2.0, 2.0, 2.0])
    result = rolling_drift(prices, window=2)
    pd.testing.assert_series_equal(result, expected)


def test_drift_downward():
    prices = pd.Series([5, 4, 3, 2, 1])
    expected = pd.Series([float("nan"), float("nan"), -2.0, -2.0, -2.0])
    result = rolling_drift(prices, window=2)
    pd.testing.assert_series_equal(result, expected)


def test_drift_flat():
    prices = pd.Series([5, 5, 5, 5])
    expected = pd.Series([float("nan"), float("nan"), 0.0, 0.0])
    result = rolling_drift(prices, window=2)
    pd.testing.assert_series_equal(result, expected)
