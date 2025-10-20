"""Strategy trading "no" shares based on time to deadline."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Mapping

import numpy as np
import pandas as pd

from fe.strategies.runtime import RuntimeStrategy, ensure_dataframe, latest_row


@dataclass
class DeadlineNoStrategy:
    """Simple strategy that buys "no" shares as a market nears resolution.

    Parameters
    ----------
    entry_days:
        Enter a long "no" position when time to deadline is less than or
        equal to this number of days.
    exit_days:
        Exit the position when time to deadline is less than or equal to this
        threshold.  Defaults to ``0`` (resolution).
    """

    entry_days: float
    exit_days: float = 0.0

    def generate_signals(self, df: pd.DataFrame) -> pd.Series:
        """Return binary trading signals for each row of ``df``.

        ``df`` must contain a ``time_to_deadline`` column expressed in days.
        Positions are ``1`` for long "no" and ``0`` for flat.
        """

        tt = df["time_to_deadline"]
        long_mask = (tt <= self.entry_days) & (tt > self.exit_days)
        return long_mask.astype(int)

    def backtest(
        self,
        df: pd.DataFrame,
        price_col: str = "no_price",
        n_shuffle: int = 100,
        seed: int | None = None,
    ) -> Dict[str, float]:
        """Run a vectorised backtest on ``df`` and return performance metrics."""

        prices = df[price_col]
        signal = self.generate_signals(df)
        position = signal.shift().fillna(0)
        returns = prices.pct_change().fillna(0)
        pnl = position * returns

        equity = (1 + pnl).cumprod()
        pnl_total = equity.iloc[-1] - 1
        sharpe = 0.0
        if pnl.std() > 0:
            sharpe = pnl.mean() / pnl.std() * np.sqrt(252)
        drawdown = (equity / equity.cummax() - 1).min()
        hit_rate = float((pnl > 0).mean())
        turnover = float(position.diff().abs().sum() / 2)

        rng = np.random.default_rng(seed)
        shuffled = []
        for _ in range(n_shuffle):
            shuffled_signal = pd.Series(rng.permutation(signal.to_numpy()), index=signal.index)
            sh_pos = shuffled_signal.shift().fillna(0)
            sh_pnl = sh_pos * returns
            if sh_pnl.std() > 0:
                shuffled.append(sh_pnl.mean() / sh_pnl.std() * np.sqrt(252))
            else:
                shuffled.append(0.0)
        pvalue = (np.sum(np.array(shuffled) >= sharpe) + 1) / (n_shuffle + 1)

        return {
            "pnl": float(pnl_total),
            "sharpe": float(sharpe),
            "max_drawdown": float(drawdown),
            "hit_rate": hit_rate,
            "turnover": turnover,
            "p_value": float(pvalue),
        }


class Strategy(RuntimeStrategy):
    """Runtime adapter that exposes the research strategy via the wheel API."""

    def __init__(
        self,
        entry_days: float,
        exit_days: float = 0.0,
        *,
        price_column: str = "no_price",
    ) -> None:
        super().__init__()
        self._strategy = DeadlineNoStrategy(entry_days=entry_days, exit_days=exit_days)
        self._price_column = price_column

    # -- Convenience wrappers -------------------------------------------------
    def generate_signals(self, df: pd.DataFrame) -> pd.Series:
        """Delegate to the research implementation for compatibility."""

        return self._strategy.generate_signals(df)

    def backtest(
        self,
        df: pd.DataFrame,
        price_col: str = "no_price",
        n_shuffle: int = 100,
        seed: int | None = None,
    ) -> Dict[str, float]:
        return self._strategy.backtest(
            df, price_col=price_col, n_shuffle=n_shuffle, seed=seed
        )

    # -- Runtime API ----------------------------------------------------------
    def propose_orders(self, market_state: Any) -> Dict[str, Any]:
        df = ensure_dataframe(market_state, columns=["time_to_deadline"])
        signals = self.generate_signals(df)
        latest_signal = int(signals.iloc[-1])
        latest_state = latest_row(df)

        orders: list[Dict[str, Any]] = []
        if latest_signal:
            price = (
                float(latest_state[self._price_column])
                if self._price_column in latest_state
                else None
            )
            orders.append(
                {
                    "contract": "no",
                    "side": "buy",
                    "size": latest_signal,
                    "price": price,
                }
            )

        return {"signals": signals, "orders": orders}

    def on_fill(self, fill: Mapping[str, Any]) -> Mapping[str, Any]:
        return super().on_fill(fill)

    def risk_profile(self) -> Mapping[str, float]:
        return {
            "max_position": 1.0,
            "entry_days": float(self._strategy.entry_days),
            "exit_days": float(self._strategy.exit_days),
        }
