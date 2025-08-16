"""Feature utilities for time-based calculations."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional


def time_to_deadline(
    deadline: Optional[datetime],
    now: Optional[datetime] = None,
) -> float:
    """Return the days until a market's resolution deadline.

    Positive values indicate the deadline is in the future while negative
    values indicate it has already passed.  If ``deadline`` is ``None`` a
    ``NaN`` value is returned.

    ``deadline`` and ``now`` may be naive or timezone-aware.  If their
    timezone awareness differs, the naive datetime is assumed to be in
    UTC.  Both values are converted to UTC before the calculation.
    """
    if deadline is None:
        return float("nan")
    if now is None:
        now = datetime.now(timezone.utc)

    def is_naive(dt: datetime) -> bool:
        return dt.tzinfo is None or dt.tzinfo.utcoffset(dt) is None

    deadline_is_naive = is_naive(deadline)
    now_is_naive = is_naive(now)

    if deadline_is_naive != now_is_naive:
        if deadline_is_naive:
            deadline = deadline.replace(tzinfo=timezone.utc)
            now = now.astimezone(timezone.utc)
        else:
            deadline = deadline.astimezone(timezone.utc)
            now = now.replace(tzinfo=timezone.utc)
    else:
        if deadline_is_naive:
            deadline = deadline.replace(tzinfo=timezone.utc)
            now = now.replace(tzinfo=timezone.utc)
        else:
            deadline = deadline.astimezone(timezone.utc)
            now = now.astimezone(timezone.utc)

    delta = deadline - now
    return delta.total_seconds() / 86400
