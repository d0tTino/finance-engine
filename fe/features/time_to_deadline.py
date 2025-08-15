"""Feature utilities for time-based calculations."""

from __future__ import annotations

from datetime import datetime
from typing import Optional


def time_to_deadline(
    deadline: Optional[datetime],
    now: Optional[datetime] = None,
) -> float:
    """Return the days until a market's resolution deadline.

    Positive values indicate the deadline is in the future while negative
    values indicate it has already passed.  If ``deadline`` is ``None`` a
    ``NaN`` value is returned.
    """
    if deadline is None:
        return float("nan")
    if now is None:
        now = datetime.utcnow()
    delta = deadline - now
    return delta.total_seconds() / 86400
