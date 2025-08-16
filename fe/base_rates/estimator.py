"""Base rate estimator using isotonic regression.

This module provides `estimate_prior` for computing outside-view priors
based on historical resolution data. Probabilities are calibrated using
isotonic regression to ensure monotonicity with respect to the time
horizon. A Wilson score interval is returned as a measure of
uncertainty.
"""
from __future__ import annotations

from datetime import datetime
from pathlib import Path
from typing import Dict, Tuple

import numpy as np
import pandas as pd
from sklearn.isotonic import IsotonicRegression

_DATA: pd.DataFrame | None = None
_MODELS: Dict[str, Tuple[IsotonicRegression, pd.DataFrame]] = {}


def _load_data() -> None:
    """Load historical resolution data and fit isotonic models."""
    global _DATA
    if _DATA is not None:
        return
    data_path = Path(__file__).with_name("historical.csv")
    _DATA = pd.read_csv(data_path)
    for cat, df_cat in _DATA.groupby("category"):
        model = IsotonicRegression(out_of_bounds="clip")
        model.fit(df_cat["horizon_days"], df_cat["outcome"])
        _MODELS[cat] = (model, df_cat)


def _wilson_interval(
    successes: float, n: int, z: float = 1.96
) -> Tuple[float, float]:
    """Return Wilson score interval for a binomial proportion."""
    if n == 0:
        return 0.0, 1.0
    phat = successes / n
    denom = 1 + z**2 / n
    center = phat + z**2 / (2 * n)
    margin = z * np.sqrt(
        (phat * (1 - phat) + z**2 / (4 * n)) / n
    )
    lower = (center - margin) / denom
    upper = (center + margin) / denom
    return float(lower), float(upper)


def estimate_prior(
    category: str, deadline: datetime
) -> Tuple[float, Tuple[float, float]]:
    """Estimate the base rate for ``category`` with ``deadline``.

    Parameters
    ----------
    category:
        Category string present in the historical data.
    deadline:
        Datetime of the event deadline.

    Returns
    -------
    prob, (lower, upper):
        Calibrated probability and 95%% confidence interval.
    """
    _load_data()
    if category not in _MODELS:
        raise ValueError(f"unknown category: {category}")

    horizon = (deadline - datetime.utcnow()).days
    horizon = max(horizon, 0)

    model, df_cat = _MODELS[category]
    prob = float(model.predict([horizon])[0])

    window = 30
    mask = (
        (df_cat["horizon_days"] >= horizon - window)
        & (df_cat["horizon_days"] <= horizon + window)
    )
    window_df = df_cat[mask]
    if window_df.empty:
        window_df = df_cat
    successes = window_df["outcome"].sum()
    n = len(window_df)
    ci = _wilson_interval(successes, n)
    return prob, ci


def evaluate_brier_score(
    test_size: float = 0.2, random_state: int | None = 0
) -> Tuple[float, float]:
    """Evaluate isotonic calibration via the Brier score.

    The historical dataset is split into training and validation
    partitions for every category.  An ``IsotonicRegression`` model is
    fitted on the training set and predictions are generated for the
    validation portion.  The Brier score of these predictions is
    compared against a naive baseline that always predicts ``0.5``
    ("flat prior").

    Parameters
    ----------
    test_size:
        Fraction of each category's data to reserve for validation.
    random_state:
        Seed used when shuffling data prior to the split.

    Returns
    -------
    score, improvement:
        ``score`` is the overall Brier score of the isotonic
        predictions on the validation data. ``improvement`` is the
        percentage reduction in Brier score relative to the flat
        baseline. A positive value indicates an improvement.
    """

    _load_data()
    if _DATA is None:
        raise RuntimeError("historical data failed to load")

    isotonic_preds: list[float] = []
    flat_preds: list[float] = []
    outcomes: list[float] = []

    rng = np.random.default_rng(random_state)

    for _, df_cat in _DATA.groupby("category"):
        if len(df_cat) < 2:
            # Need at least one point for training and one for testing.
            continue

        df_cat = df_cat.sample(frac=1, random_state=rng.integers(0, 2**32))
        split = int(len(df_cat) * (1 - test_size))
        split = min(max(split, 1), len(df_cat) - 1)
        train = df_cat.iloc[:split]
        test = df_cat.iloc[split:]

        model = IsotonicRegression(out_of_bounds="clip")
        model.fit(train["horizon_days"], train["outcome"])

        preds = model.predict(test["horizon_days"])
        isotonic_preds.extend(preds.tolist())
        flat_preds.extend([0.5] * len(test))
        outcomes.extend(test["outcome"].tolist())

    if not outcomes:
        raise RuntimeError("not enough data to evaluate Brier score")

    outcomes_arr = np.asarray(outcomes)
    iso_arr = np.asarray(isotonic_preds)
    flat_arr = np.asarray(flat_preds)

    iso_score = float(np.mean((iso_arr - outcomes_arr) ** 2))
    flat_score = float(np.mean((flat_arr - outcomes_arr) ** 2))
    improvement = 0.0
    if flat_score > 0:
        improvement = 100.0 * (flat_score - iso_score) / flat_score
    return iso_score, improvement
