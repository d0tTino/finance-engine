import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[2]))

from datetime import datetime, timedelta  # noqa: E402

from fe.base_rates import estimate_prior  # noqa: E402


def test_estimate_prior_mid_horizon():
    deadline = datetime.utcnow() + timedelta(days=75)
    prob, ci = estimate_prior("politics", deadline)
    assert 0.45 < prob < 0.55
    assert ci[0] < prob < ci[1]


def test_estimate_prior_unknown_category():
    deadline = datetime.utcnow() + timedelta(days=30)
    try:
        estimate_prior("unknown", deadline)
    except ValueError:
        pass
    else:
        raise AssertionError("expected ValueError for unknown category")
