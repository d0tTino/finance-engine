import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.rules_alpha import (  # noqa: E402
    SlippageModel,
    _score_rules,
    grid_search,
    performance_report,
    simulate,
    walk_forward,
)


@pytest.fixture
def sample_frame() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "price": [
                0.40,
                0.60,
                0.55,
                0.50,
                0.52,
                0.48,
            ],
            "rule": [
                "Official results will be used",
                "May be decided by committee",
                "",
                "Exact figure will be announced within 10 days",
                "Official tally must be published",
                "Decision may be roughly estimated",
            ],
        },
        index=pd.RangeIndex(6),
    )


def test_simulate_and_performance_report(sample_frame: pd.DataFrame) -> None:
    data = sample_frame.iloc[:4]
    model = SlippageModel(rate=0.0)
    returns = simulate(data, threshold=0.5, slippage=model)
    assert returns.tolist() == pytest.approx([0.2, 0.05, 0.05])

    report = performance_report(returns)
    assert report["trades"] == 3
    assert report["avg_return"] == pytest.approx(0.1)
    assert report["sharpe"] == pytest.approx(np.sqrt(2))


def test_score_rules_and_slippage_model_behaviour() -> None:
    scores = _score_rules(
        [
            "Official data will be published",  # clear
            "Outcome may be determined at discretion",  # ambiguous
            None,
        ]
    )
    np.testing.assert_array_equal(scores, np.array([1.0, -1.0, 0.0]))

    model = SlippageModel(rate=0.05)
    assert model.apply(0.50, 1) == pytest.approx(0.525)
    assert model.apply(0.50, -1) == pytest.approx(0.475)


def test_simulate_and_report_empty_inputs() -> None:
    data = pd.DataFrame(columns=["price", "rule"])
    model = SlippageModel(rate=0.0)
    returns = simulate(data, threshold=0.5, slippage=model)
    assert returns.empty
    assert returns.dtype == float

    report = performance_report(returns)
    assert report == {"trades": 0, "avg_return": 0.0, "sharpe": 0.0}


def test_grid_search_selects_expected_parameters(sample_frame: pd.DataFrame) -> None:
    data = sample_frame.iloc[:4]
    result = grid_search(data, thresholds=[0.0, 0.5], slippages=[0.0, 0.05])

    assert result["params"] == {"threshold": 0.5, "slippage": 0.0}
    assert result["report"]["trades"] == 3
    assert result["report"]["avg_return"] == pytest.approx(0.1)


def test_walk_forward_structure_and_parameter_usage(sample_frame: pd.DataFrame) -> None:
    result = walk_forward(
        sample_frame,
        train_size=3,
        test_size=2,
        thresholds=[0.0, 0.5],
        slippages=[0.0, 0.05],
    )

    assert result.shape == (1, 6)
    assert list(result.columns) == [
        "trades",
        "avg_return",
        "sharpe",
        "threshold",
        "slippage",
        "start",
    ]
    assert result.loc[0, "start"] == 3
    assert result.loc[0, "threshold"] == 0.0
    assert result.loc[0, "slippage"] == 0.0
    assert result.loc[0, "trades"] == 1
    assert result.loc[0, "avg_return"] == pytest.approx(0.02)

