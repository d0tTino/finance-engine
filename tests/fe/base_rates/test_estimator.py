import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.base_rates import evaluate_brier_score  # noqa: E402
import fe.base_rates.estimator as estimator  # noqa: E402


def test_historical_brier_score_improves_over_baseline():
    estimator._DATA = None
    estimator._MODELS = {}
    _, improvement = evaluate_brier_score(random_state=1)
    assert improvement > 0
