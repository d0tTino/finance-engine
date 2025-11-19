"""Base rate estimator using isotonic regression.

This module provides `estimate_prior` for computing outside-view priors
based on historical resolution data. Historical rows are first mapped to
taxonomy-defined horizon buckets before fitting isotonic regression
models, ensuring calibration respects the declared time scopes for each
category. Probabilities are calibrated using isotonic regression to
ensure monotonicity with respect to the time horizon. A Wilson score
interval is returned as a measure of uncertainty.
"""
from __future__ import annotations

import argparse
import json
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Dict, Iterable, Tuple

import numpy as np
import pandas as pd
import yaml
from sklearn.isotonic import IsotonicRegression

_DATA: pd.DataFrame | None = None
@dataclass
class CategoryModel:
    """Container holding the calibrated model and bucket metadata."""

    model: IsotonicRegression
    rows: pd.DataFrame
    bucket_summary: pd.DataFrame


_MODELS: Dict[str, CategoryModel] = {}
_TAXONOMY: dict[str, dict] | None = None


@dataclass(frozen=True)
class HorizonDefinition:
    """Definition of a taxonomy horizon bucket."""

    name: str
    index: int
    min_days: int | None
    max_days: int | None


_CATEGORY_HORIZONS: Dict[str, list[HorizonDefinition]] = {}
_BUCKET_NAME_LOOKUP: Dict[Tuple[str, int], str] = {}


def _prepare_taxonomy_structures(
    taxonomy: Dict[str, dict]
) -> Tuple[Dict[str, list[HorizonDefinition]], Dict[Tuple[str, int], str]]:
    """Return processed taxonomy helpers for quick lookups."""

    category_horizons: Dict[str, list[HorizonDefinition]] = {}
    bucket_lookup: Dict[Tuple[str, int], str] = {}

    for category, info in taxonomy.items():
        horizons = info.get("horizons") or {}
        processed: list[HorizonDefinition] = []
        for idx, (name, details) in enumerate(horizons.items()):
            min_days = details.get("min_days")
            max_days = details.get("max_days")
            if min_days is not None:
                min_days = int(min_days)
            if max_days is not None:
                max_days = int(max_days)
            horizon_def = HorizonDefinition(name=name, index=idx, min_days=min_days, max_days=max_days)
            processed.append(horizon_def)
            bucket_lookup[(category, idx)] = name
        category_horizons[category] = processed

    return category_horizons, bucket_lookup


def _load_taxonomy() -> Dict[str, dict]:
    """Load taxonomy metadata and cached helpers."""

    global _TAXONOMY, _CATEGORY_HORIZONS, _BUCKET_NAME_LOOKUP
    if _TAXONOMY is not None:
        return _TAXONOMY

    taxonomy_path = Path(__file__).with_name("taxonomy.yaml")
    _TAXONOMY = yaml.safe_load(taxonomy_path.read_text()) or {}
    _CATEGORY_HORIZONS, _BUCKET_NAME_LOOKUP = _prepare_taxonomy_structures(_TAXONOMY)
    return _TAXONOMY


def _find_bucket(category: str, horizon_days: int) -> HorizonDefinition | None:
    """Return the taxonomy bucket matching ``horizon_days``."""

    _load_taxonomy()
    horizons = _CATEGORY_HORIZONS.get(category, [])
    if not horizons:
        return None

    for horizon in horizons:
        min_ok = horizon.min_days is None or horizon_days >= horizon.min_days
        max_ok = horizon.max_days is None or horizon_days <= horizon.max_days
        if min_ok and max_ok:
            return horizon

    # If no direct match was found, clamp to the closest bucket by range.
    first = horizons[0]
    last = horizons[-1]
    if first.min_days is not None and horizon_days < first.min_days:
        return first
    return last


def _bucketize_dataframe(df: pd.DataFrame) -> pd.DataFrame:
    """Attach taxonomy bucket metadata to the historical dataset."""

    rows: Iterable[dict] = []
    for record in df.to_dict("records"):
        category = record.get("category")
        horizon_days = int(record.get("horizon_days", 0))
        bucket = _find_bucket(category, horizon_days)
        if bucket is None:
            # Skip rows that cannot be mapped to a taxonomy bucket.
            continue
        record["horizon_bucket"] = bucket.name
        record["bucket_index"] = bucket.index
        rows.append(record)
    if not rows:
        return pd.DataFrame(columns=list(df.columns) + ["horizon_bucket", "bucket_index"])
    bucketed = pd.DataFrame(rows)
    bucketed["bucket_index"] = bucketed["bucket_index"].astype(int)
    return bucketed


def _load_data() -> None:
    """Load historical resolution data and fit isotonic models."""

    global _DATA
    if _DATA is not None:
        return

    data_path = Path(__file__).with_name("historical.csv")
    raw = pd.read_csv(data_path)
    _load_taxonomy()
    _DATA = _bucketize_dataframe(raw)

    for cat, df_cat in _DATA.groupby("category"):
        if df_cat.empty:
            continue
        summary = (
            df_cat.groupby(["bucket_index", "horizon_bucket"], as_index=False)
            .agg(successes=("outcome", "sum"), total=("outcome", "size"))
            .sort_values("bucket_index")
        )
        summary["bucket_index"] = summary["bucket_index"].astype(int)
        summary["total"] = summary["total"].astype(int)
        rates = summary["successes"] / summary["total"]
        model = IsotonicRegression(out_of_bounds="clip")
        model.fit(
            summary["bucket_index"],
            rates,
            sample_weight=summary["total"],
        )
        _MODELS[cat] = CategoryModel(model=model, rows=df_cat, bucket_summary=summary)


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

    horizon_days = (deadline - datetime.utcnow()).days
    horizon_days = max(horizon_days, 0)
    bucket = _find_bucket(category, horizon_days)
    if bucket is None:
        raise ValueError(f"no taxonomy horizon for category '{category}'")

    cat_model = _MODELS[category]
    prob = float(cat_model.model.predict([bucket.index])[0])

    bucket_df = cat_model.rows[cat_model.rows["bucket_index"] == bucket.index]
    if bucket_df.empty:
        bucket_df = cat_model.rows
    successes = bucket_df["outcome"].sum()
    n = len(bucket_df)
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

    for category, cat_model in _MODELS.items():
        df_cat = cat_model.rows
        if len(df_cat) < 2:
            # Need at least one point for training and one for testing.
            continue

        df_cat = df_cat.sample(frac=1, random_state=rng.integers(0, 2**32))
        split = int(len(df_cat) * (1 - test_size))
        split = min(max(split, 1), len(df_cat) - 1)
        train = df_cat.iloc[:split]
        test = df_cat.iloc[split:]

        model = IsotonicRegression(out_of_bounds="clip")
        model.fit(train["bucket_index"], train["outcome"])

        preds = model.predict(test["bucket_index"])
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


def evaluate_brier_by_taxonomy(
    test_size: float = 0.2, random_state: int | None = 0
) -> Dict[str, Dict[str, Tuple[float, float]]]:
    """Evaluate Brier scores for each taxonomy bucket.

    Taxonomy categories are defined in ``taxonomy.yaml``. For each
    top-level category present in the historical data, this function
    computes the Brier score of the isotonic regression predictions and
    the percentage improvement over a flat ``0.5`` prior.

    Parameters
    ----------
    test_size:
        Fraction of each category's data to reserve for validation.
    random_state:
        Seed used when shuffling data prior to the split.

    Returns
    -------
    Dict[str, Dict[str, Tuple[float, float]]]
        Mapping of taxonomy category to its horizon bucket scores.
    """

    _load_data()
    if _DATA is None:
        raise RuntimeError("historical data failed to load")

    results: Dict[str, Dict[str, Tuple[float, float]]] = {}
    rng = np.random.default_rng(random_state)
    taxonomy = _load_taxonomy()
    for category, cat_model in _MODELS.items():
        df_cat = cat_model.rows
        if category not in taxonomy:
            continue
        if len(df_cat) < 2:
            continue

        df_cat = df_cat.sample(
            frac=1, random_state=rng.integers(0, 2**32)
        )
        split = int(len(df_cat) * (1 - test_size))
        split = min(max(split, 1), len(df_cat) - 1)
        train = df_cat.iloc[:split]
        test = df_cat.iloc[split:]

        model = IsotonicRegression(out_of_bounds="clip")
        model.fit(train["bucket_index"], train["outcome"])

        preds = model.predict(test["bucket_index"])
        test = test.assign(pred=preds)

        cat_results: Dict[str, Tuple[float, float]] = {}
        for bucket_index, df_bucket in test.groupby("bucket_index"):
            bucket_name = _BUCKET_NAME_LOOKUP.get((category, int(bucket_index)))
            if bucket_name is None or df_bucket.empty:
                continue
            iso_score = float(
                np.mean((df_bucket["pred"] - df_bucket["outcome"]) ** 2)
            )
            flat_score = float(
                np.mean((0.5 - df_bucket["outcome"]) ** 2)
            )
            improvement = 0.0
            if flat_score > 0:
                improvement = 100.0 * (flat_score - iso_score) / flat_score
            cat_results[bucket_name] = (iso_score, improvement)
        if cat_results:
            results[category] = cat_results

    return results


def cli() -> None:
    """Command-line interface for base rate evaluation utilities."""
    parser = argparse.ArgumentParser(
        description="Base rate estimator utilities"
    )
    sub = parser.add_subparsers(dest="command", required=True)

    parser_score = sub.add_parser(
        "evaluate-brier-score", help="Evaluate overall Brier score"
    )
    parser_score.add_argument(
        "--test-size",
        type=float,
        default=0.2,
        help="Fraction of data reserved for validation",
    )
    parser_score.add_argument(
        "--random-state",
        type=int,
        default=0,
        help="Seed for reproducibility",
    )

    parser_taxonomy = sub.add_parser(
        "evaluate-brier-by-taxonomy",
        help="Evaluate Brier score per taxonomy bucket",
    )
    parser_taxonomy.add_argument(
        "--test-size",
        type=float,
        default=0.2,
        help="Fraction of data reserved for validation",
    )
    parser_taxonomy.add_argument(
        "--random-state",
        type=int,
        default=0,
        help="Seed for reproducibility",
    )

    args = parser.parse_args()
    if args.command == "evaluate-brier-score":
        score, improvement = evaluate_brier_score(
            args.test_size, args.random_state
        )
        print(json.dumps({"score": score, "improvement": improvement}))
    elif args.command == "evaluate-brier-by-taxonomy":
        results = evaluate_brier_by_taxonomy(
            args.test_size, args.random_state
        )
        print(json.dumps(results, indent=2))


if __name__ == "__main__":
    cli()
