"""Trading strategies leveraging cross-market features."""

from .cross_pairs import (
    compute_signals,
    vectorized_backtest,
    grid_search,
    shuffled_label_significance,
)

__all__ = [
    "compute_signals",
    "vectorized_backtest",
    "grid_search",
    "shuffled_label_significance",
]
