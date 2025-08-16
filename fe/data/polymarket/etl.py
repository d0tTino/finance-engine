"""Polymarket ETL script.

Downloads daily price history, order book snapshots, metadata,
and resolution information for resolved Polymarket markets.

Output is stored as Parquet files partitioned by event_date and
category. The script is idempotent and skips markets that have
already been processed.
"""
from __future__ import annotations

import json
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List

import pandas as pd
import requests

BASE_URL = "https://gamma-api.polymarket.com"
CLOB_URL = "https://clob.polymarket.com"
OUTPUT_DIR = Path(__file__).resolve().parents[3] / "data" / "polymarket"


def _get(url: str, params: Dict[str, Any] | None = None) -> Any:
    resp = requests.get(url, params=params, timeout=30)
    resp.raise_for_status()
    if resp.headers.get("content-type", "").startswith("application/json"):
        return resp.json()
    return resp.text


def get_resolved_markets(days: int = 365) -> List[Dict[str, Any]]:
    """Return resolved markets in the past ``days`` days."""
    since = datetime.utcnow() - timedelta(days=days)
    markets: List[Dict[str, Any]] = []
    limit = 1000
    offset = 0
    while True:
        data = _get(
            f"{BASE_URL}/markets",
            {
                "state": "resolved",
                "limit": limit,
                "offset": offset,
                "order": "-endDate",
            },
        )
        if not data:
            break
        for market in data:
            end = datetime.fromisoformat(market["endDate"].replace("Z", "+00:00"))
            if end >= since:
                markets.append(market)
        if len(data) < limit or datetime.fromisoformat(data[-1]["endDate"].replace("Z", "+00:00")) < since:
            break
        offset += limit
    return markets


def fetch_price_history(market_id: str) -> List[Dict[str, Any]]:
    """Return daily price history for a market.

    The Polymarket API has several endpoints for price history.  We
    request aggregated daily trades.  If the endpoint is unavailable,
    an empty list is returned so that the ETL run continues.
    """
    try:
        return _get(f"{BASE_URL}/market/{market_id}/trades", {"resolution": "d"})
    except Exception:
        return []


def fetch_order_book(market_id: str) -> Dict[str, Any]:
    """Return order book snapshot for a market."""
    try:
        return _get(f"{CLOB_URL}/markets/{market_id}/book")
    except Exception:
        return {}


def fetch_clarification_resolution_events(market_id: str) -> List[Dict[str, Any]]:
    """Return clarification/resolution events for a market."""
    try:
        return _get(
            f"{BASE_URL}/clarification/resolution-events",
            {"market": market_id},
        )
    except Exception:
        return []


def save_market(market: Dict[str, Any]) -> None:
    event = market.get("events", [{}])[0]
    event_date_str = event.get("endDate") or market.get("endDate")
    event_date = datetime.fromisoformat(event_date_str.replace("Z", "+00:00")).date()
    category = market.get("category", "unknown")

    out_dir = OUTPUT_DIR / f"event_date={event_date.isoformat()}" / f"category={category}"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_file = out_dir / f"market_{market['id']}.parquet"
    if out_file.exists():
        return  # idempotency

    record = {
        "market": market,
        "price_history": fetch_price_history(market["id"]),
        "order_book": fetch_order_book(market["id"]),
        "clarification_resolution_events": fetch_clarification_resolution_events(
            market["id"]
        ),
        "resolution": {
            "description": market.get("resolutionDescription"),
            "sources": market.get("resolutionSources"),
        },
    }
    df = pd.DataFrame({"data": [json.dumps(record)]})
    df.to_parquet(out_file, index=False)


def main() -> None:
    markets = get_resolved_markets()
    for market in markets:
        save_market(market)


if __name__ == "__main__":
    main()
