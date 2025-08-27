import pathlib
import sys
from datetime import datetime, timezone, timedelta
import math

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

from fe.features.time_to_deadline import time_to_deadline  # noqa: E402
from pytest import approx  # noqa: E402


def test_time_to_deadline_none():
    assert math.isnan(time_to_deadline(None))


def test_time_to_deadline_mixed_naive_and_aware():
    deadline = datetime(2024, 1, 2)
    now = datetime(2024, 1, 1, tzinfo=timezone.utc)
    assert time_to_deadline(deadline, now) == approx(1.0)


def test_time_to_deadline_both_naive():
    deadline = datetime(2024, 1, 2)
    now = datetime(2024, 1, 1)
    assert time_to_deadline(deadline, now) == approx(1.0)


def test_time_to_deadline_both_aware():
    deadline = datetime(2024, 1, 1, tzinfo=timezone.utc)
    now = datetime(2023, 12, 31, 23, tzinfo=timezone(timedelta(hours=1)))
    expected = 2 / 24  # two hours difference in days
    assert time_to_deadline(deadline, now) == approx(expected)


def test_time_to_deadline_default_now():
    deadline = datetime.now(timezone.utc) + timedelta(days=1)
    result = time_to_deadline(deadline)
    assert result == approx(1.0, rel=1e-3)


def test_time_to_deadline_deadline_aware_now_naive():
    deadline = datetime(2024, 1, 2, tzinfo=timezone.utc)
    now = datetime(2024, 1, 1)
    assert time_to_deadline(deadline, now) == approx(1.0)
