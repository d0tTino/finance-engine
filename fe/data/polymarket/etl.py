"""Polymarket ETL script.

Downloads daily price history, order book snapshots, metadata,
and resolution information for resolved Polymarket markets.

Output is stored as Parquet files partitioned by event_date and
category. The script is idempotent and skips markets that have
already been processed.
"""
from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional

import pandas as pd
import requests
from requests.adapters import HTTPAdapter
from urllib3.util import retry

from .schemas import (
    ClarificationResolutionEvent,
    MarketMetadata,
    OrderBookSnapshot,
    PriceHistory,
)

BASE_URL = "https://gamma-api.polymarket.com"
CLOB_URL = "https://clob.polymarket.com"

_DEFAULT_OUTPUT_DIR = (
    Path(
        os.environ.get(
            "POLYMARKET_OUTPUT_DIR",
            Path(__file__).resolve().parents[3] / "data" / "polymarket",
        )
    )
    .resolve()
)


_session = requests.Session()
_retries = retry.Retry(
    total=3,
    backoff_factor=1,
    status_forcelist=[429, 500, 502, 503, 504],
    allowed_methods=["GET"],
)
_adapter = HTTPAdapter(max_retries=_retries)
_session.mount("https://", _adapter)
_session.mount("http://", _adapter)


def _get(url: str, params: Dict[str, Any] | None = None) -> Any:
    resp = _session.get(url, params=params, timeout=30)
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
            end = datetime.fromisoformat(
                market["endDate"].replace("Z", "+00:00")
            )
            if end >= since:
                markets.append(market)
        last_end = datetime.fromisoformat(
            data[-1]["endDate"].replace("Z", "+00:00")
        )
        if len(data) < limit or last_end < since:
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
        return _get(
            f"{BASE_URL}/market/{market_id}/trades",
            {"resolution": "d"},
        )
    except Exception:
        return []


def fetch_order_book(market_id: str) -> Dict[str, Any]:
    """Return order book snapshot for a market."""
    try:
        return _get(f"{CLOB_URL}/markets/{market_id}/book")
    except Exception:
        return {}


def fetch_clarification_resolution_events(
    market_id: str,
) -> List[Dict[str, Any]]:
    """Return clarification/resolution events for a market."""
    try:
        return _get(
            f"{BASE_URL}/clarification/resolution-events",
            {"market": market_id},
        )
    except Exception:
        return []


def save_market(
    market: Dict[str, Any], output_dir: Optional[Path] = None
) -> None:
    event = market.get("events", [{}])[0]
    event_date_str = event.get("endDate") or market.get("endDate")
    event_date = datetime.fromisoformat(
        event_date_str.replace("Z", "+00:00")
    ).date()
    category = market.get("category", "unknown")

    base_output = Path(output_dir) if output_dir else _DEFAULT_OUTPUT_DIR
    out_dir = (
        base_output
        / f"event_date={event_date.isoformat()}"
        / f"category={category}"
    )
    out_dir.mkdir(parents=True, exist_ok=True)
    out_file = out_dir / f"market_{market['id']}.parquet"
    if out_file.exists():
        return  # idempotency

    try:
        metadata = MarketMetadata(
            market_id=market["id"],
            question=market.get("question", ""),
            outcomes=market.get("outcomes", []),
            created_at=market.get("createdAt"),
            event_date=event_date,
            category=category,
        )
    except Exception:
        return

    price_history: List[Dict[str, Any]] = []
    for ph in fetch_price_history(market["id"]):
        ts = ph.get("timestamp") or ph.get("time") or ph.get("t")
        price = ph.get("price") or ph.get("p")
        if ts is None or price is None:
            continue
        try:
            model = PriceHistory(
                market_id=market["id"],
                timestamp=ts,
                price=price,
                event_date=event_date,
                category=category,
            )
            price_history.append(model.model_dump())
        except Exception:
            continue

    order_book_data = fetch_order_book(market["id"])
    order_book: Dict[str, Any] | None = None
    ts = (
        order_book_data.get("timestamp")
        or order_book_data.get("time")
        or order_book_data.get("t")
    )
    bids = order_book_data.get("bids")
    asks = order_book_data.get("asks")
    if ts and bids and asks:
        try:
            ob_model = OrderBookSnapshot(
                market_id=market["id"],
                timestamp=ts,
                bids=bids,
                asks=asks,
                event_date=event_date,
                category=category,
            )
            order_book = ob_model.model_dump()
        except Exception:
            order_book = None

    events: List[Dict[str, Any]] = []
    for ev in fetch_clarification_resolution_events(market["id"]):
        ts = ev.get("timestamp") or ev.get("time") or ev.get("t")
        event_type = ev.get("event_type") or ev.get("type")
        message = ev.get("message") or ev.get("msg")
        if ts is None or event_type is None or message is None:
            continue
        try:
            model = ClarificationResolutionEvent(
                market_id=market["id"],
                timestamp=ts,
                event_type=event_type,
                message=message,
                event_date=event_date,
                category=category,
            )
            events.append(model.model_dump())
        except Exception:
            continue

    record = {
        "market": metadata.model_dump(),
        "price_history": price_history,
        "order_book": order_book,
        "clarification_resolution_events": events,
        "resolution": {
            "description": market.get("resolutionDescription"),
            "sources": market.get("resolutionSources"),
        },
    }
    df = pd.DataFrame({"data": [json.dumps(record)]})
    df.to_parquet(out_file, index=False)


def assert_partitions(output_dir: Path, days: int = 365) -> None:
    """Ensure output directory has partitions for the last ``days`` days."""
    today = datetime.utcnow().date()
    missing: List[str] = []
    for i in range(days):
        day = today - timedelta(days=i)
        event_dir = output_dir / f"event_date={day.isoformat()}"
        if not event_dir.exists() or not any(event_dir.glob("category=*")):
            missing.append(day.isoformat())
    if missing:
        raise AssertionError(
            "missing partitions for: " + ", ".join(sorted(missing))
        )


def main(output_dir: Optional[Path] = None, days: int = 365) -> None:
    markets = get_resolved_markets(days)
    for market in markets:
        save_market(market, output_dir)
    assert_partitions(output_dir or _DEFAULT_OUTPUT_DIR, days)


def cli() -> None:
    parser = argparse.ArgumentParser(description="Polymarket ETL")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=None,
        help="Directory for output parquet files",
    )
    parser.add_argument(
        "--days",
        type=int,
        default=365,
        help="Number of days of resolved markets to process",
    )
    args = parser.parse_args()
    main(args.output_dir, args.days)


if __name__ == "__main__":
    cli()
