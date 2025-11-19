import sys
from datetime import datetime, timedelta
from pathlib import Path

import math
import numpy as np
import pandas as pd
import pytest
import yaml

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.base_rates import evaluate_brier_score, evaluate_brier_by_taxonomy  # noqa: E402
import fe.base_rates.estimator as estimator  # noqa: E402


@pytest.fixture
def mock_taxonomy_and_historical(monkeypatch):
    taxonomy = {
        "geopolitics": {
            "horizons": {
                "near_term": {"min_days": 0, "max_days": 10},
                "long_term": {"min_days": 11, "max_days": None},
            }
        },
        "sector": {
            "horizons": {
                "tactical": {"min_days": 0, "max_days": 40},
                "strategic": {"min_days": 41, "max_days": None},
            }
        },
    }
    taxonomy_yaml = yaml.safe_dump(taxonomy)

    data = pd.DataFrame(
        {
            "category": [
                "geopolitics"
            ]
            * 6
            + ["geopolitics"] * 6
            + ["sector"] * 6
            + ["sector"] * 6,
            "horizon_days":
            [2, 3, 5, 7, 8, 9]
            + [15, 20, 25, 28, 32, 35]
            + [5, 10, 20, 25, 30, 35]
            + [45, 55, 65, 75, 85, 95],
            "outcome":
            [1, 1, 1, 1, 1, 1]
            + [0, 0, 0, 0, 0, 0]
            + [0, 0, 1, 0, 1, 0]
            + [1, 1, 1, 1, 1, 1],
        }
    )

    taxonomy_path = Path(estimator.__file__).with_name("taxonomy.yaml")
    data_path = Path(estimator.__file__).with_name("historical.csv")

    estimator._DATA = None
    estimator._MODELS = {}
    estimator._TAXONOMY = None
    estimator._CATEGORY_HORIZONS = {}
    estimator._BUCKET_NAME_LOOKUP = {}

    original_read_text = Path.read_text

    def fake_read_text(self, *args, **kwargs):
        if self == taxonomy_path:
            return taxonomy_yaml
        return original_read_text(self, *args, **kwargs)

    monkeypatch.setattr(Path, "read_text", fake_read_text)

    original_read_csv = pd.read_csv

    def fake_read_csv(path, *args, **kwargs):
        if Path(path) == data_path:
            return data.copy()
        return original_read_csv(path, *args, **kwargs)

    monkeypatch.setattr(pd, "read_csv", fake_read_csv)
    return taxonomy, data


def test_historical_brier_score_improves_over_baseline(mock_taxonomy_and_historical):
    _, improvement = evaluate_brier_score(random_state=1)
    assert improvement > 0


def test_taxonomy_brier_scores_cover_all_horizons(mock_taxonomy_and_historical):
    taxonomy, _ = mock_taxonomy_and_historical
    results = evaluate_brier_by_taxonomy(random_state=1)
    assert set(results) == set(taxonomy)
    for category, horizons in results.items():
        assert set(horizons) == set(taxonomy[category]["horizons"])
        for score, improvement in horizons.values():
            assert score >= 0
            assert math.isfinite(improvement)


def test_estimate_prior_uses_horizon_buckets(mock_taxonomy_and_historical):
    now = datetime.utcnow()
    prob_near, ci_near = estimator.estimate_prior(
        "geopolitics", now + timedelta(days=5)
    )
    prob_long, ci_long = estimator.estimate_prior(
        "geopolitics", now + timedelta(days=30)
    )

    assert prob_near > prob_long
    assert prob_near > 0.8
    assert prob_long < 0.2

    for ci in (ci_near, ci_long):
        assert len(ci) == 2 and not any(math.isnan(v) for v in ci)
        assert all(0 <= v <= 1 for v in ci)


def test_bucket_summary_tracks_taxonomy_horizons(mock_taxonomy_and_historical):
    taxonomy, _ = mock_taxonomy_and_historical
    estimator._load_data()
    geopolitics_model = estimator._MODELS["geopolitics"]
    assert set(geopolitics_model.bucket_summary["horizon_bucket"]) == set(
        taxonomy["geopolitics"]["horizons"].keys()
    )
    assert geopolitics_model.bucket_summary["total"].sum() == 12


def test_brier_score_training_uses_bucket_indices(
    monkeypatch, mock_taxonomy_and_historical
):
    taxonomy, _ = mock_taxonomy_and_historical
    expected_indices: set[int] = set()
    for info in taxonomy.values():
        expected_indices.update(range(len(info["horizons"])))

    seen: list[set[int]] = []
    original_fit = estimator.IsotonicRegression.fit

    def spy(self, X, y, sample_weight=None):  # type: ignore[override]
        unique_indices = set(np.unique(np.asarray(X, dtype=int)).tolist())
        seen.append(unique_indices)
        return original_fit(self, X, y, sample_weight=sample_weight)

    monkeypatch.setattr(estimator.IsotonicRegression, "fit", spy)
    evaluate_brier_score(random_state=2, test_size=0.5)
    assert seen, "expected at least one fit call"
    for indices in seen:
        assert indices <= expected_indices
