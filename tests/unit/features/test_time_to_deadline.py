import sys
from pathlib import Path
from datetime import datetime, timezone

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.features.time_to_deadline import time_to_deadline  # noqa: E402


@pytest.mark.parametrize(
    "deadline, now",
    [
        (datetime(2024, 1, 2, tzinfo=timezone.utc), datetime(2024, 1, 1)),
        (datetime(2024, 1, 2), datetime(2024, 1, 1, tzinfo=timezone.utc)),
    ],
)
def test_naive_and_aware_inputs(deadline, now):
    result = time_to_deadline(deadline, now)
    assert result == pytest.approx(1)
