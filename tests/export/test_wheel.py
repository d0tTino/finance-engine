from __future__ import annotations

import importlib
import subprocess
import sys
from pathlib import Path

import pytest

pd = pytest.importorskip("pandas")


STRATEGY_CASES = {
    "deadline_no": {
        "init_kwargs": {"entry_days": 3, "exit_days": 1},
        "market_state": pd.DataFrame(
            {
                "time_to_deadline": [5, 2],
                "no_price": [0.6, 0.5],
            }
        ),
        "backtest_args": {
            "df": pd.DataFrame(
                {
                    "time_to_deadline": [6, 4, 3, 2],
                    "no_price": [0.6, 0.59, 0.58, 0.57],
                }
            ),
            "price_col": "no_price",
            "n_shuffle": 0,
        },
        "profile_keys": {"max_position", "entry_days", "exit_days"},
        "expect_orders": True,
        "backtest_check": lambda result: "pnl" in result,
    },
    "cross_pairs": {
        "init_kwargs": {"pairs": [("A", "B")], "lookback": 2, "threshold": 0.1},
        "market_state": pd.DataFrame(
            {
                "A": [1.0, 1.01, 1.02, 1.03],
                "B": [0.9, 0.91, 0.92, 0.9],
            }
        ),
        "backtest_args": {
            "prices": pd.DataFrame(
                {
                    "A": [1.0, 1.01, 1.02, 1.03],
                    "B": [0.9, 0.91, 0.92, 0.9],
                }
            )
        },
        "profile_keys": {"pairs", "lookback", "threshold"},
        "expect_orders": True,
        "backtest_check": lambda result: not result.empty,
    },
    "maker": {
        "init_kwargs": {
            "spread": 0.02,
            "liq_weight": 0.5,
            "skew_weight": 0.5,
            "slippage": 0.01,
        },
        "market_state": pd.DataFrame(
            {
                "yes": [0.55, 0.56, 0.57],
                "no": [0.45, 0.44, 0.43],
                "liquidity": [0.4, 0.6, 0.5],
                "skew": [0.05, 0.0, -0.05],
            }
        ),
        "backtest_args": {
            "df": pd.DataFrame(
                {
                    "yes": [0.55, 0.56, 0.57],
                    "no": [0.45, 0.44, 0.43],
                    "liquidity": [0.4, 0.6, 0.5],
                    "skew": [0.05, 0.0, -0.05],
                }
            )
        },
        "profile_keys": {"max_spread", "slippage", "liquidity_weight", "skew_weight"},
        "expect_orders": True,
        "backtest_check": lambda result: {"pnl", "bid_edge", "ask_edge"}.issubset(result.columns),
    },
    "rules_alpha": {
        "init_kwargs": {"threshold": 0.0, "slippage": 0.001},
        "market_state": pd.DataFrame(
            {
                "price": [0.4, 0.42, 0.43],
                "rule": ["clear", "ambiguous", "clear"],
            }
        ),
        "backtest_args": {
            "df": pd.DataFrame(
                {
                    "price": [0.4, 0.42, 0.43, 0.41],
                    "rule": ["clear", "ambiguous", "clear", "unknown"],
                }
            )
        },
        "profile_keys": {"threshold", "slippage"},
        "expect_orders": True,
        "backtest_check": lambda result: {"trades", "avg_return", "sharpe"}.issubset(result.keys()),
    },
}


def test_wheel_install_and_strategy_load(tmp_path: Path) -> None:
    """Install the built wheel into a temporary env and exercise strategies."""

    root = Path(__file__).resolve().parents[2]
    dist = root / "dist"
    wheels = list(dist.glob("*.whl"))
    if not wheels:
        pytest.skip("No wheel built to test")
    wheel = wheels[0]

    target = tmp_path / "site"
    subprocess.run(
        [
            sys.executable,
            "-m",
            "pip",
            "install",
            "--no-deps",
            "--target",
            str(target),
            str(wheel),
        ],
        check=True,
    )

    sys.path.insert(0, str(target))

    from polymarket_alpha import Strategy as RuntimeStrategy

    for name, case in STRATEGY_CASES.items():
        module = importlib.import_module(f"polymarket_alpha.strategies.{name}")
        StrategyCls = getattr(module, "Strategy")

        assert issubclass(StrategyCls, RuntimeStrategy)
        strat = StrategyCls(**case["init_kwargs"])

        proposal = strat.propose_orders(case["market_state"])
        assert "orders" in proposal
        assert isinstance(proposal["orders"], list)
        if case.get("expect_orders", False):
            assert proposal["orders"], f"expected at least one order from {name}"

        fill_state = strat.on_fill({"id": 1, "size": 1})
        assert "fills" in fill_state and len(fill_state["fills"]) == 1

        profile = strat.risk_profile()
        for key in case["profile_keys"]:
            assert key in profile

        backtest_kwargs = case["backtest_args"]
        backtest_result = strat.backtest(**backtest_kwargs)
        assert case["backtest_check"](backtest_result)
