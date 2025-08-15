import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.append(str(Path(__file__).resolve().parents[2]))

from fe.strategies.deadline_no import DeadlineNoStrategy  # noqa: E402
from fe.backtest import walk_forward_validation  # noqa: E402


def make_data(n: int = 60) -> pd.DataFrame:
    tt = np.linspace(30, -1, n)
    prices = 0.5 + 0.1 * np.sin(np.linspace(0, 3, n))
    return pd.DataFrame({"time_to_deadline": tt, "no_price": prices})


def test_strategy_metrics():
    df = make_data()
    strat = DeadlineNoStrategy(entry_days=10, exit_days=0)
    metrics = strat.backtest(df)
    assert set("pnl sharpe max_drawdown hit_rate turnover shuffled_pvalue".split()).issubset(metrics)
    assert 0 <= metrics["hit_rate"] <= 1
    assert 0 <= metrics["shuffled_pvalue"] <= 1


def test_walk_forward_validation():
    df = make_data(80)
    param_grid = {"entry_days": [12, 10], "exit_days": [0, -1]}
    results = walk_forward_validation(df, DeadlineNoStrategy, param_grid, train_size=40, test_size=10)
    assert not results.empty
    assert {"pnl", "sharpe", "max_drawdown", "hit_rate", "turnover", "shuffled_pvalue"}.issubset(results.columns)
    # ensure parameters used are from grid
    assert set(results["entry_days"]).issubset({12, 10})
    assert set(results["exit_days"]).issubset({0, -1})
