import sys
from datetime import datetime, timedelta
from pathlib import Path

import math
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.base_rates import evaluate_brier_score  # noqa: E402
import fe.base_rates.estimator as estimator  # noqa: E402


@pytest.fixture
def mock_historical(monkeypatch):
    data = pd.DataFrame(
        {
            "category": ["politics"] * 10 + ["sports"] * 10,
            "horizon_days": list(range(1, 11)) * 2,
            "outcome": [1] * 4 + [0] * 6 + [0] * 4 + [1] * 6,
        }
    )

    def fake_load_data():
        estimator._DATA = data
        estimator._MODELS = {}
        for cat, df_cat in data.groupby("category"):
            model = estimator.IsotonicRegression(out_of_bounds="clip")
            model.fit(df_cat["horizon_days"], df_cat["outcome"])
            estimator._MODELS[cat] = (model, df_cat)

    monkeypatch.setattr(estimator, "_load_data", fake_load_data)
    estimator._DATA = None
    estimator._MODELS = {}
    return data


def test_historical_brier_score_improves_over_baseline(mock_historical):
    _, improvement = evaluate_brier_score(random_state=1)
    assert improvement > 0


def test_estimate_prior_returns_no_nulls(mock_historical):
    prob, ci = estimator.estimate_prior(
        "politics", datetime.utcnow() + timedelta(days=10)
    )
    assert 0 <= prob <= 1 and not math.isnan(prob)
    assert len(ci) == 2 and not any(math.isnan(v) for v in ci)
    assert all(0 <= v <= 1 for v in ci)
