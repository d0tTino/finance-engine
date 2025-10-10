"""Market making strategy utilities.

This module provides functions to quote two-sided markets using
liquidity and skew features as inputs.  It also offers a simple
slippage-aware backtesting framework with parameter tuning and
computation of summary performance metrics.
"""

from __future__ import annotations

import itertools
from dataclasses import dataclass
from typing import Any, Dict, Iterable, Mapping, Tuple

import pandas as pd

from fe.strategies.runtime import RuntimeStrategy, ensure_dataframe, latest_row


@dataclass(frozen=True)
class Quote:
    """Bid/ask quote."""

    bid: float
    ask: float


def make_quote(
    yes: float,
    no: float,
    liquidity: float,
    skew: float,
    *,
    spread: float = 0.02,
    liq_weight: float = 0.5,
    skew_weight: float = 0.5,
) -> Quote:
    """Return bid/ask quote around the mid price.

    The width is contracted when liquidity is high and shifted according
    to the market skew.
    """

    mid = 0.5 * (yes + no)
    raw_width = spread * (1 - liq_weight * liquidity)
    min_width = 0.0
    width = max(raw_width, min_width)
    adjustment = skew_weight * skew * width

    bid = max(0.0, mid - width / 2 - adjustment)
    ask = min(1.0, mid + width / 2 - adjustment)

    return Quote(bid=bid, ask=ask)


def run_backtest(
    df: pd.DataFrame,
    *,
    spread: float,
    liq_weight: float,
    skew_weight: float,
    slippage: float = 0.01,
) -> pd.DataFrame:
    """Run a slippage-aware backtest of the maker strategy.

    Parameters
    ----------
    df:
        DataFrame with columns ``yes``, ``no``, ``liquidity`` and ``skew``.
    spread, liq_weight, skew_weight:
        Parameters passed to :func:`make_quote`.
    slippage:
        Executed trades are penalized by this absolute amount.

    Returns
    -------
    pd.DataFrame
        DataFrame with columns ``bid_edge``, ``ask_edge`` and ``pnl``
        for each timestamp.
    """

    quotes = df.apply(
        lambda r: make_quote(
            r["yes"],
            r["no"],
            r["liquidity"],
            r["skew"],
            spread=spread,
            liq_weight=liq_weight,
            skew_weight=skew_weight,
        ),
        axis=1,
    )
    qdf = pd.DataFrame(list(quotes), index=df.index)

    bid_edge = qdf["bid"] - df["yes"] - slippage
    ask_edge = df["no"] - qdf["ask"] - slippage
    pnl = 0.5 * (bid_edge + ask_edge)

    return pd.DataFrame({"bid_edge": bid_edge, "ask_edge": ask_edge, "pnl": pnl})


def performance_metrics(results: pd.DataFrame) -> Dict[str, float]:
    """Compute summary metrics from backtest results."""

    trades = results[["bid_edge", "ask_edge"]].stack()
    win_rate = float((trades > 0).mean())
    mean_pnl = float(results["pnl"].mean())
    return {"mean_pnl": mean_pnl, "win_rate": win_rate}


def tune_parameters(
    df: pd.DataFrame,
    spreads: Iterable[float],
    liq_weights: Iterable[float],
    skew_weights: Iterable[float],
    *,
    slippage: float = 0.01,
) -> Tuple[Dict[str, float], Dict[str, float]]:
    """Grid search best parameters based on mean PnL."""

    best_params: Dict[str, float] | None = None
    best_metrics: Dict[str, float] | None = None
    best_score = float("-inf")

    for s, lw, sw in itertools.product(spreads, liq_weights, skew_weights):
        res = run_backtest(
            df, spread=s, liq_weight=lw, skew_weight=sw, slippage=slippage
        )
        metrics = performance_metrics(res)
        score = metrics["mean_pnl"]
        if score > best_score:
            best_score = score
            best_params = {"spread": s, "liq_weight": lw, "skew_weight": sw}
            best_metrics = metrics

    assert best_params is not None and best_metrics is not None
    return best_params, best_metrics


class Strategy(RuntimeStrategy):
    """Adapter that turns the quoting research helpers into a runtime strategy."""

    def __init__(
        self,
        *,
        spread: float,
        liq_weight: float,
        skew_weight: float,
        slippage: float = 0.01,
    ) -> None:
        super().__init__()
        self.spread = float(spread)
        self.liq_weight = float(liq_weight)
        self.skew_weight = float(skew_weight)
        self.slippage = float(slippage)

    # Research compatibility --------------------------------------------------
    def make_quote(self, yes: float, no: float, liquidity: float, skew: float) -> Quote:
        return make_quote(
            yes,
            no,
            liquidity,
            skew,
            spread=self.spread,
            liq_weight=self.liq_weight,
            skew_weight=self.skew_weight,
        )

    def backtest(self, df: pd.DataFrame) -> pd.DataFrame:
        return run_backtest(
            df,
            spread=self.spread,
            liq_weight=self.liq_weight,
            skew_weight=self.skew_weight,
            slippage=self.slippage,
        )

    # Runtime interface -------------------------------------------------------
    def propose_orders(self, market_state: Any) -> Dict[str, Any]:
        df = ensure_dataframe(
            market_state, columns=["yes", "no", "liquidity", "skew"]
        )
        latest = latest_row(df)
        quote = self.make_quote(
            float(latest["yes"]),
            float(latest["no"]),
            float(latest["liquidity"]),
            float(latest["skew"]),
        )

        quote_dict = {"bid": float(quote.bid), "ask": float(quote.ask)}
        orders = [
            {"side": "bid", "price": quote_dict["bid"], "size": 1.0},
            {"side": "ask", "price": quote_dict["ask"], "size": 1.0},
        ]
        return {"quote": quote_dict, "orders": orders}

    def on_fill(self, fill: Mapping[str, Any]) -> Mapping[str, Any]:
        return super().on_fill(fill)

    def risk_profile(self) -> Dict[str, float]:
        return {
            "max_spread": self.spread,
            "slippage": self.slippage,
            "liquidity_weight": self.liq_weight,
            "skew_weight": self.skew_weight,
        }


__all__ = [
    "Quote",
    "make_quote",
    "run_backtest",
    "performance_metrics",
    "tune_parameters",
    "Strategy",
]
