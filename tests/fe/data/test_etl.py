import sys
from pathlib import Path

import pandas as pd
import json

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.data.polymarket import etl  # noqa: E402


def test_save_market_idempotent(tmp_path, monkeypatch):
    market = {
        "id": "123",
        "endDate": "2020-11-04T00:00:00Z",
        "category": "politics",
    }
    price_history = [{"timestamp": "2020-11-04T00:00:00Z", "price": 0.5}]
    order_book = {"bids": [], "asks": []}
    events_payload = [
        {
            "id": 1,
            "timestamp": "2020-11-05T00:00:00Z",
            "type": "resolution",
            "message": "resolved",
        }
    ]

    def fake_get(url, params=None):
        if "clarification/resolution-events" in url:
            assert params == {"market": "123"}
            return events_payload
        if url.endswith("/trades"):
            return price_history
        if url.endswith("/book"):
            return order_book
        raise AssertionError(f"unexpected url {url}")

    monkeypatch.setattr(etl, "_get", fake_get)
    monkeypatch.setattr(etl, "OUTPUT_DIR", tmp_path)

    etl.save_market(market)
    etl.save_market(market)

    out_dir = tmp_path / "event_date=2020-11-04" / "category=politics"
    parquet_files = list(out_dir.glob("market_123.parquet"))
    assert len(parquet_files) == 1

    df = pd.read_parquet(parquet_files[0])
    record = df["data"].map(json.loads).iloc[0]
    assert record["price_history"] == price_history
    assert record["order_book"] == order_book
    assert record["clarification_resolution_events"] == events_payload
