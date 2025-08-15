"""Simple gate for approving strategy reports.

A strategy is approved if it satisfies three conditions:
- ``sample_size`` is at least ``MIN_SAMPLE``
- ``max_drawdown`` does not exceed ``MAX_MDD``
- ``p_value`` is below ``ALPHA``
"""
from __future__ import annotations

from typing import Mapping

MIN_SAMPLE = 30
MAX_MDD = 0.2
ALPHA = 0.05


def approve(strategy_report: Mapping[str, float | int]) -> bool:
    """Return True if the strategy report passes all gates.

    Parameters
    ----------
    strategy_report:
        Mapping containing ``sample_size``, ``max_drawdown`` and ``p_value``.

    Returns
    -------
    bool
        True when all metrics meet their respective thresholds.
    """
    sample = strategy_report.get("sample_size", 0)
    mdd = strategy_report.get("max_drawdown", float("inf"))
    pval = strategy_report.get("p_value", 1.0)
    return sample >= MIN_SAMPLE and mdd <= MAX_MDD and pval < ALPHA


__all__ = ["approve", "MIN_SAMPLE", "MAX_MDD", "ALPHA"]
