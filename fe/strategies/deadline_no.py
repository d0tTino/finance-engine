"""Strategy trading "no" shares based on time to deadline."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Dict

import numpy as np
import pandas as pd


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
            "shuffled_pvalue": float(pvalue),
        }
