import pandas as pd

import fe.base_rates.estimator as estimator


def test_evaluate_brier_by_taxonomy_improves_over_flat_prior():
    categories = [
        "geopolitics",
        "macroeconomics",
        "sector",
        "market_microstructure",
    ]
    data = {
        "category": [],
        "horizon_days": [],
        "outcome": [],
    }
    for cat in categories:
        data["category"].extend([cat] * 10)
        data["horizon_days"].extend(range(10, 110, 10))
        data["outcome"].extend([0] * 5 + [1] * 5)
    sample_df = pd.DataFrame(data)
    estimator._DATA = sample_df
    estimator._MODELS = {}

    results = estimator.evaluate_brier_by_taxonomy()
    assert set(results.keys()) == set(categories)
    for score, improvement in results.values():
        assert score < 0.25
        assert improvement > 0
