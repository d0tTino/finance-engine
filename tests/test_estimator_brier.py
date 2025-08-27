import pathlib
import sys

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

import pandas as pd  # noqa: E402
from fe.base_rates import evaluate_brier_score  # noqa: E402
import fe.base_rates.estimator as estimator  # noqa: E402


def test_brier_score_improvement_positive():
    df = pd.DataFrame({
        "category": ["sample"] * 10,
        "horizon_days": list(range(10, 110, 10)),
        "outcome": [0] * 5 + [1] * 5,
    })
    estimator._DATA = df
    estimator._MODELS = {}
    score, improvement = evaluate_brier_score()
    assert improvement > 0
    assert 0 <= score <= 1
