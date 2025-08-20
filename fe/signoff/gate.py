"""Simple gate for approving strategy reports.

A strategy is approved if it satisfies three conditions:
- ``sample_size`` is at least ``MIN_SAMPLE``
- ``max_drawdown`` does not exceed ``MAX_MDD``
- ``p_value`` is below ``ALPHA``

Thresholds may be overridden via function parameters or a JSON configuration
file.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Mapping

MIN_SAMPLE = 30
MAX_MDD = 0.2
ALPHA = 0.05


def _load_config(path: str | Path | None) -> dict[str, float | int]:
    """Load thresholds from ``path`` if it exists."""
    cfg_path = Path(path) if path is not None else Path(__file__).with_name(
        "config.json"
    )
    if cfg_path.is_file():
        with cfg_path.open() as handle:
            return json.load(handle)
    return {}


def approve(
    strategy_report: Mapping[str, float | int],
    *,
    min_sample: int | None = None,
    max_mdd: float | None = None,
    alpha: float | None = None,
    config_path: str | Path | None = None,
) -> bool:
    """Return True if the strategy report passes all gates.

    Parameters
    ----------
    strategy_report:
        Mapping containing ``sample_size``, ``max_drawdown`` and ``p_value``.
    min_sample, max_mdd, alpha:
        Optional overrides for the approval thresholds.
    config_path:
        Optional path to a JSON config file containing threshold values.

    Returns
    -------
    bool
        True when all metrics meet their respective thresholds.
    """
    config = _load_config(config_path)
    sample_threshold = min_sample if min_sample is not None else config.get(
        "MIN_SAMPLE", MIN_SAMPLE
    )
    mdd_threshold = max_mdd if max_mdd is not None else config.get(
        "MAX_MDD", MAX_MDD
    )
    alpha_threshold = alpha if alpha is not None else config.get("ALPHA", ALPHA)

    sample = strategy_report.get("sample_size", 0)
    mdd = strategy_report.get("max_drawdown", float("inf"))
    pval = strategy_report.get("p_value", 1.0)
    return sample >= sample_threshold and mdd <= mdd_threshold and pval < alpha_threshold


__all__ = ["approve", "MIN_SAMPLE", "MAX_MDD", "ALPHA"]
