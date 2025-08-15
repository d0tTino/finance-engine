import sys
from pathlib import Path

import pandas as pd
from pytest import approx

sys.path.append(str(Path(__file__).resolve().parents[1]))

from fe.strategies.maker import (  # noqa: E402
    make_quote,
    performance_metrics,
    run_backtest,
    tune_parameters,
)


def sample_data() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "yes": [0.5, 0.55],
            "no": [0.5, 0.45],
            "liquidity": [0.2, 0.8],
            "skew": [0.0, 0.1],
        }
    )


def test_make_quote_uses_features() -> None:
    quote = make_quote(0.6, 0.4, liquidity=1.0, skew=0.2, spread=0.1, liq_weight=0.5, skew_weight=0.5)
    assert quote.bid == approx(0.47)
    assert quote.ask == approx(0.52)


def test_backtest_and_metrics() -> None:
    data = sample_data()
    res = run_backtest(data, spread=0.1, liq_weight=0.5, skew_weight=0.5, slippage=0.01)
    metrics = performance_metrics(res)
    assert set(res.columns) == {"bid_edge", "ask_edge", "pnl"}
    assert "mean_pnl" in metrics and "win_rate" in metrics


def test_tune_parameters() -> None:
    data = sample_data()
    params, metrics = tune_parameters(
        data, spreads=[0.05, 0.1], liq_weights=[0.5], skew_weights=[0.5], slippage=0.01
    )
    assert set(params) == {"spread", "liq_weight", "skew_weight"}
    assert "mean_pnl" in metrics
