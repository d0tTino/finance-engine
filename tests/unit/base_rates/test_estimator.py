import pandas as pd

from fe.base_rates import evaluate_brier_score
import fe.base_rates.estimator as estimator


def test_evaluate_brier_score_improves_over_flat_prior():
    sample_df = pd.DataFrame(
        {
            "category": ["sample"] * 10,
            "horizon_days": list(range(10, 110, 10)),
            "outcome": [0] * 5 + [1] * 5,
        }
    )
    estimator._DATA = sample_df
    estimator._MODELS = {}

    score, improvement = evaluate_brier_score()
    assert score < 0.25  # better than flat prior Brier score
    assert improvement > 0
