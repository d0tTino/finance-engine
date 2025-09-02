import sys
from datetime import datetime
from pathlib import Path
import json

import pandas as pd
import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.data.polymarket import etl  # noqa: E402


@pytest.fixture
def run_sample_market(tmp_path, monkeypatch):
    today = datetime.utcnow().date().isoformat()
    market = {
        "id": "123",
        "endDate": f"{today}T00:00:00Z",
        "category": "politics",
        "question": "Is today special?",
        "outcomes": ["yes", "no"],
        "createdAt": f"{today}T00:00:00Z",
    }
    price_history = [{"timestamp": f"{today}T00:00:00Z", "price": 0.5}]
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

    def fake_get_resolved_markets(days: int = 365):
        return [market]

    monkeypatch.setattr(etl, "_get", fake_get)
    monkeypatch.setattr(etl, "get_resolved_markets", fake_get_resolved_markets)
    original_dumps = json.dumps
    monkeypatch.setattr(
        etl.json,
        "dumps",
        lambda obj, **kwargs: original_dumps(
            obj, default=kwargs.pop("default", str), **kwargs
        ),
    )

    def run() -> None:
        etl.main(output_dir=tmp_path, days=1)

    return run, tmp_path, price_history, order_book, events_payload


def test_run_idempotent(run_sample_market):
    (
        run,
        tmp_path,
        price_history,
        order_book,
        events_payload,
    ) = run_sample_market
    run()
    run()
    event_dir = datetime.utcnow().date().isoformat()
    out_dir = tmp_path / f"event_date={event_dir}" / "category=politics"
    parquet_files = list(out_dir.glob("market_123.parquet"))
    assert len(parquet_files) == 1

    df = pd.read_parquet(parquet_files[0])
    record = df["data"].map(json.loads).iloc[0]
    assert record["price_history"][0]["price"] == price_history[0]["price"]
    assert record["order_book"] is None
    assert (
        record["clarification_resolution_events"][0]["message"]
        == events_payload[0]["message"]
    )
