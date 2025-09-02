import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.walk_forward import (  # noqa: E402
    aggregate_performance,
    walk_forward_splits,
)


def test_walk_forward_splits_alignment():
    data = pd.DataFrame({"price": np.arange(10)}, index=np.arange(10))
    splits = list(walk_forward_splits(data, window=4, step=2))
    assert len(splits) == 3
    for i, (train, test) in enumerate(splits):
        expected_train = np.arange(i * 2, i * 2 + 4)
        expected_test = np.arange(4 + i * 2, 4 + i * 2 + 2)
        assert (train.index.values == expected_train).all()
        assert (test.index.values == expected_test).all()


def test_aggregate_performance():
    returns = [0.1, -0.05, 0.02]
    perf = aggregate_performance(returns)
    assert perf["return"].tolist() == returns
    cumulative = 1.0
    expected = []
    for r in returns:
        cumulative *= 1.0 + r
        expected.append(cumulative - 1.0)
    assert perf["cumulative"].tolist() == pytest.approx(expected, rel=1e-6)
