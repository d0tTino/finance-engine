"""Rule objectivity based trading strategy.

This module provides a simple alpha factor that favours markets with
"clear" resolution rules while avoiding those with "ambiguous" rules.
It also includes utilities for applying a basic slippage model, running
parameter grid searches, performing walk-forward evaluation and
producing performance reports.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, Mapping, Sequence
import itertools

import numpy as np
import pandas as pd

from fe.features.rule_objectivity import batch_score
from fe.strategies.runtime import RuntimeStrategy, ensure_dataframe, latest_row

# Map textual objectivity scores to numerical alpha values.
_OBJECTIVITY_ALPHA = {"clear": 1.0, "unknown": 0.0, "ambiguous": -1.0}


@dataclass
class SlippageModel:
    """Linear slippage model.

    Parameters
    ----------
    rate:
        Fractional slippage applied to each trade.  A ``rate`` of 0.001
        represents 10 bps of price impact.
    """

    rate: float = 0.001

    def apply(self, price: float, side: int) -> float:
        """Return price adjusted for slippage.

        ``side`` should be ``1`` for buys and ``-1`` for sells.
        """

        adjustment = price * self.rate
        return price + adjustment if side > 0 else price - adjustment


def _score_rules(rules: Sequence[str]) -> np.ndarray:
    """Vectorised rule scoring helper."""

    scores = batch_score(rules)
    return np.array([_OBJECTIVITY_ALPHA[s] for s in scores], dtype=float)


def simulate(df: pd.DataFrame, threshold: float, slippage: SlippageModel) -> pd.Series:
    """Simulate strategy returns for ``df``.

    Parameters
    ----------
    df:
        DataFrame containing columns ``price`` and ``rule``.
    threshold:
        Minimum alpha required to go long.  Values below ``threshold``
        result in short positions.
    slippage:
        Slippage model applied to trade prices.
    """

    if df.empty:
        returns = pd.Series(dtype=float)
        returns.attrs["meta"] = {
            "prices": [],
            "rules": [],
            "threshold": float(threshold),
            "slippage": float(slippage.rate),
            "positions": [],
        }
        return returns

    alpha = _score_rules(df["rule"].tolist())
    side = np.where(alpha >= threshold, 1, -1)
    prices = df["price"].astype(float).to_numpy()
    executed = np.array([slippage.apply(p, s) for p, s in zip(prices, side)])
    # Compute P&L from price changes in direction of the position.
    pnl = (np.roll(executed, -1) - executed) * side
    returns = pd.Series(pnl[:-1], index=df.index[:-1])
    returns.attrs["meta"] = {
        "prices": prices.tolist(),
        "rules": df["rule"].tolist(),
        "threshold": float(threshold),
        "slippage": float(slippage.rate),
        "positions": side[:-1].tolist(),
    }
    return returns


def performance_report(
    returns: pd.Series,
    *,
    permutations: int = 200,
    seed: int | None = 0,
) -> Dict[str, float]:
    """Compute comprehensive performance statistics."""

    if returns.empty:
        return {
            "trades": 0,
            "sample_size": 0,
            "avg_return": 0.0,
            "pnl": 0.0,
            "sharpe": 0.0,
            "max_drawdown": 0.0,
            "hit_rate": 0.0,
            "turnover": 0.0,
            "p_value": float("nan"),
        }

    pnl = float(returns.sum())
    avg = float(returns.mean())
    std = float(returns.std(ddof=0))
    sharpe = avg / std if std else 0.0

    cumulative = np.cumsum(returns.to_numpy())
    equity = np.concatenate(([0.0], cumulative))
    running_max = np.maximum.accumulate(equity)
    drawdowns = equity - running_max
    max_drawdown = float(drawdowns.min())

    hit_rate = float(np.mean(returns.to_numpy() > 0))

    meta = returns.attrs.get("meta", {})
    positions = np.asarray(meta.get("positions", []), dtype=float)
    if positions.size:
        deltas = np.diff(np.concatenate(([0.0], positions)))
        turnover = float(np.sum(np.abs(deltas)))
    else:
        turnover = 0.0

    prices = meta.get("prices")
    rules = meta.get("rules")
    threshold = meta.get("threshold")
    slippage = meta.get("slippage")
    if (
        permutations > 0
        and prices is not None
        and rules is not None
        and threshold is not None
        and slippage is not None
        and len(prices) > 1
    ):
        rng = np.random.default_rng(seed)
        base_df = pd.DataFrame({"price": prices, "rule": rules})
        sample = np.empty(permutations, dtype=float)
        for i in range(permutations):
            shuffled = rng.permutation(rules)
            simulated = simulate(
                base_df.assign(rule=shuffled),
                float(threshold),
                SlippageModel(rate=float(slippage)),
            )
            sample[i] = float(simulated.mean()) if not simulated.empty else 0.0
        p_value = float((np.sum(sample >= avg) + 1) / (permutations + 1))
    else:
        p_value = float("nan")

    return {
        "trades": int(returns.size),
        "sample_size": int(returns.size),
        "avg_return": avg,
        "pnl": pnl,
        "sharpe": sharpe,
        "max_drawdown": max_drawdown,
        "hit_rate": hit_rate,
        "turnover": turnover,
        "p_value": p_value,
    }


def grid_search(df: pd.DataFrame, thresholds: Iterable[float], slippages: Iterable[float]) -> Dict[str, Any]:
    """Evaluate parameter combinations and return the best."""

    best_params: Dict[str, float] | None = None
    best_report: Dict[str, float] | None = None
    for threshold, slip in itertools.product(thresholds, slippages):
        model = SlippageModel(rate=slip)
        ret = simulate(df, threshold, model)
        report = performance_report(ret)
        if best_report is None or report["avg_return"] > best_report["avg_return"]:
            best_params = {"threshold": threshold, "slippage": slip}
            best_report = report
    return {"params": best_params, "report": best_report}


def walk_forward(
    df: pd.DataFrame,
    train_size: int,
    test_size: int,
    thresholds: Iterable[float],
    slippages: Iterable[float],
) -> pd.DataFrame:
    """Perform walk-forward evaluation.

    The data is split into sequential train/test windows.  For each
    window a grid search is executed on the training portion and the
    best parameters are applied to the subsequent test slice.  A
    performance report for each period is returned as a DataFrame.
    """

    results: list[Dict[str, Any]] = []
    start = 0
    while start + train_size + test_size <= len(df):
        train = df.iloc[start : start + train_size]
        test = df.iloc[start + train_size : start + train_size + test_size]
        params = grid_search(train, thresholds, slippages)["params"]
        model = SlippageModel(rate=params["slippage"])
        ret = simulate(test, params["threshold"], model)
        rep = performance_report(ret)
        rep.update(params)
        rep["start"] = test.index[0]
        results.append(rep)
        start += test_size
    return pd.DataFrame(results)


class Strategy(RuntimeStrategy):
    """Adapter that satisfies the runtime Strategy ABC."""

    def __init__(self, *, threshold: float, slippage: float = 0.001) -> None:
        super().__init__()
        self.threshold = float(threshold)
        self.slippage_model = SlippageModel(rate=float(slippage))

    # Research compatibility --------------------------------------------------
    def simulate(self, df: pd.DataFrame) -> pd.Series:
        return simulate(df, self.threshold, self.slippage_model)

    def performance_report(self, returns: pd.Series) -> Dict[str, float]:
        return performance_report(returns)

    def backtest(self, df: pd.DataFrame) -> Dict[str, float]:
        returns = self.simulate(df)
        return self.performance_report(returns)

    # Runtime interface -------------------------------------------------------
    def propose_orders(self, market_state: Any) -> Dict[str, Any]:
        df = ensure_dataframe(market_state, columns=["price", "rule"])
        latest = latest_row(df)
        score = float(_score_rules([latest["rule"]])[0])
        side = 1 if score >= self.threshold else -1
        executed_price = self.slippage_model.apply(float(latest["price"]), side)

        orders = [
            {
                "side": "buy" if side > 0 else "sell",
                "price": executed_price,
                "size": 1.0,
                "alpha": score,
            }
        ]
        return {"orders": orders, "alpha": score}

    def on_fill(self, fill: Mapping[str, Any]) -> Mapping[str, Any]:
        return super().on_fill(fill)

    def risk_profile(self) -> Dict[str, float]:
        return {
            "threshold": self.threshold,
            "slippage": self.slippage_model.rate,
        }


__all__ = [
    "SlippageModel",
    "simulate",
    "performance_report",
    "grid_search",
    "walk_forward",
    "Strategy",
]
