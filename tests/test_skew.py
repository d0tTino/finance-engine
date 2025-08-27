import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[1]))

from fe.features.skew import price_skew  # noqa: E402
from pytest import approx  # noqa: E402


def test_symmetric_market_has_zero_skew() -> None:
    assert price_skew(0.5, 0.5) == approx(0)


def test_skew_is_normalized() -> None:
    assert price_skew(1.0, 0.0) == approx(1)
    assert price_skew(0.0, 1.0) == approx(-1)


def test_skew_zero_prices() -> None:
    assert price_skew(0.0, 0.0) == approx(0)
