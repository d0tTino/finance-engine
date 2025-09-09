import sys
from pathlib import Path
from datetime import datetime, timedelta, timezone

import pandas as pd

sys.path.append(str(Path(__file__).resolve().parents[2]))

from fe.features.liquidity import compute_liquidity
from fe.features.drift import rolling_drift
from fe.features.skew import price_skew
from fe.features.rule_objectivity import batch_score
from fe.features.time_to_deadline import time_to_deadline
from fe.features.coverage import missing_features


def test_feature_coverage(tmp_path):
    """Ensure feature functions cover sample Polymarket data."""
    now = datetime(2024, 1, 1, tzinfo=timezone.utc)
    market_ids = [f"m{i}" for i in range(40)]

    # Build order book snapshots for liquidity
    order_rows = [
        {
            "market_id": m_id,
            "timestamp": now,
            "bids": [[0.9, 10]],
            "asks": [[1.1, 5]],
        }
        for m_id in market_ids
    ]
    order_df = pd.DataFrame(order_rows)
    liquidity = compute_liquidity(order_df, hours=2)

    # Build price history for drift
    price_rows = []
    for m_id in market_ids:
        price_rows.extend(
            [
                {"market_id": m_id, "timestamp": now - timedelta(hours=1), "price": 0.4},
                {"market_id": m_id, "timestamp": now, "price": 0.6},
            ]
        )
    price_df = pd.DataFrame(price_rows)
    drift = price_df.groupby("market_id")["price"].apply(lambda s: rolling_drift(s, 1).iloc[-1])

    # Static skew and rule scores
    skew = pd.Series({m_id: price_skew(0.6, 0.4) for m_id in market_ids})
    rules = ["Will be determined by an official source."] * len(market_ids)
    rule_scores = pd.Series(batch_score(rules), index=market_ids)

    # Deadlines with one missing value
    deadlines = {
        m_id: (None if i == 0 else now + timedelta(days=10))
        for i, m_id in enumerate(market_ids)
    }
    ttd = pd.Series({m_id: time_to_deadline(dl, now) for m_id, dl in deadlines.items()})

    features = pd.concat([liquidity, drift, skew, rule_scores, ttd], axis=1)
    features.columns = [
        "liquidity",
        "drift",
        "skew",
        "rule_objectivity",
        "time_to_deadline",
    ]
    features = features.reset_index().rename(columns={"index": "market_id"})

    path = tmp_path / "features.parquet"
    features.to_parquet(path)

    missing = missing_features([str(path)])
    null_rates = features.drop(columns=["market_id"]).isna().mean()
    failing = null_rates[null_rates > 0.05]
    if not failing.empty:
        missing_counts = missing.sum()
        raise AssertionError(
            f"Null rates above 5%: {failing.to_dict()}, missing_features={missing_counts.to_dict()}"
        )
