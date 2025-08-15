import sys
from pathlib import Path
from datetime import datetime, timedelta
from math import isnan

import pytest

sys.path.append(str(Path(__file__).resolve().parents[2]))
from fe.features.time_to_deadline import time_to_deadline  # noqa: E402


def test_future_deadline():
    now = datetime(2024, 1, 1)
    deadline = now + timedelta(days=5)
    result = time_to_deadline(deadline, now=now)
    assert result == pytest.approx(5)


def test_past_deadline():
    now = datetime(2024, 1, 10)
    deadline = now - timedelta(days=3)
    result = time_to_deadline(deadline, now=now)
    assert result == pytest.approx(-3)


def test_null_deadline():
    now = datetime(2024, 1, 1)
    result = time_to_deadline(None, now=now)
    assert isnan(result)
