import sys
from pathlib import Path
from datetime import datetime, timedelta, timezone

import pandas as pd

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.features.time_to_deadline import time_to_deadline  # noqa: E402
from fe.features.liquidity import compute_liquidity  # noqa: E402
from fe.features.skew import price_skew  # noqa: E402
from fe.features.drift import rolling_drift  # noqa: E402
from fe.features.rule_objectivity import batch_score  # noqa: E402


def load_resolved_market_sample() -> pd.DataFrame:
    """Return a synthetic resolved market sample with feature columns."""
    n_markets = 100
    now = datetime(2024, 1, 1, tzinfo=timezone.utc)
    market_ids = [f"m{i}" for i in range(n_markets)]

    # Deadlines with a few missing values
    deadlines = [now + timedelta(days=i) for i in range(n_markets)]
    for i in range(4):
        deadlines[i] = None
    ttd = pd.Series([time_to_deadline(d) for d in deadlines], index=market_ids)

    # Order book snapshots for liquidity; omit last four markets
    ob_rows = []
    for mid in market_ids[:-4]:
        for j in range(3):
            ob_rows.append(
                {
                    "market_id": mid,
                    "timestamp": now + timedelta(hours=j),
                    "bids": [[0.5, 10.0]],
                    "asks": [[0.6, 10.0]],
                }
            )
    ob_df = pd.DataFrame(ob_rows)
    liquidity = compute_liquidity(ob_df).reindex(market_ids)

    # Prices for skew with some NaNs
    step = 0.8 / (n_markets - 1)
    yes_prices = [0.1 + i * step for i in range(n_markets)]
    no_prices = [1 - p for p in yes_prices]
    yes_prices[:4] = [float("nan")] * 4
    skew = pd.Series(
        [price_skew(y, n) for y, n in zip(yes_prices, no_prices)], index=market_ids
    )

    # Price history for drift; last four markets have insufficient data
    ph_rows = []
    for mid in market_ids[:-4]:
        prices = [0.4, 0.5, 0.6]
        for j, price in enumerate(prices):
            ph_rows.append({"market_id": mid, "price": price + 0.01 * j})
    for mid in market_ids[-4:]:
        ph_rows.append({"market_id": mid, "price": 0.5})
    ph_df = pd.DataFrame(ph_rows)
    drift = (
        ph_df.groupby("market_id")["price"]
        .apply(lambda s: rolling_drift(s, window=1).iloc[-1])
        .reindex(market_ids)
    )

    # Rules for objectivity; treat 'unknown' as missing
    rules = ["This will be officially determined based on data."] * n_markets
    for i in range(4):
        rules[i] = ""
    rule_scores = (
        pd.Series(batch_score(rules), index=market_ids)
        .replace("unknown", pd.NA)
    )

    return pd.DataFrame(
        {
            "time_to_deadline": ttd,
            "liquidity": liquidity,
            "skew": skew,
            "drift": drift,
            "rule_objectivity": rule_scores,
        }
    )


def test_feature_nulls_below_threshold():
    df = load_resolved_market_sample()
    for col in [
        "time_to_deadline",
        "liquidity",
        "skew",
        "drift",
        "rule_objectivity",
    ]:
        null_ratio = df[col].isna().mean()
        assert null_ratio < 0.05, f"{col} null ratio {null_ratio:.2%} exceeds threshold"
