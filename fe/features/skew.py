"""Market skew utilities.

Measures the price imbalance between the yes and no sides of a binary
market.  Positive values indicate the yes side is priced higher than
the no side.  The result is normalized to the range [-1, 1].
"""

from __future__ import annotations


def price_skew(yes: float, no: float) -> float:
    """Return normalized price imbalance between ``yes`` and ``no`` prices.

    When both sides are equally priced the skew is zero.  The output
    ranges from -1 (``no`` priced at 1, ``yes`` at 0) to 1 (``yes`` priced
    at 1, ``no`` at 0).  If both prices are zero, a skew of 0 is
    returned.
    """
    total = yes + no
    if total <= 0:
        return 0.0
    return (yes - no) / total
