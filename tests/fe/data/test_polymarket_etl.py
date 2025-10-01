import logging
import sys
from datetime import datetime, timedelta
from pathlib import Path
import json

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.data.polymarket import etl  # noqa: E402


class DummyHTTPError(Exception):
    """Exception used to simulate HTTP failures in tests."""


def test_save_market_creates_expected_partitions(tmp_path, monkeypatch):
    market = {
        "id": "m1",
        "question": "Q?",
        "outcomes": ["yes", "no"],
        "events": [{"endDate": "2024-05-10T00:00:00Z"}],
        "category": "politics",
        "createdAt": "2024-05-01T00:00:00Z",
    }

    monkeypatch.setattr(
        etl,
        "fetch_price_history",
        lambda market_id: [
            {"timestamp": "2024-05-10T00:00:00Z", "price": 0.5},
        ],
    )
    monkeypatch.setattr(
        etl,
        "fetch_order_book",
        lambda market_id: {
            "timestamp": "2024-05-10T00:00:00Z",
            "bids": [],
            "asks": [],
        },
    )
    monkeypatch.setattr(
        etl,
        "fetch_clarification_resolution_events",
        lambda market_id: [
            {
                "timestamp": "2024-05-10T00:00:00Z",
                "event_type": "resolution",
                "message": "done",
            }
        ],
    )

    original_dumps = json.dumps
    written_records = []

    def capturing_dumps(obj, **kwargs):
        default = kwargs.pop("default", str)
        written_records.append(obj)
        return original_dumps(obj, default=default, **kwargs)

    monkeypatch.setattr(etl.json, "dumps", capturing_dumps)

    etl.save_market(market, tmp_path)

    expected_dir = tmp_path / "event_date=2024-05-10" / "category=politics"
    assert expected_dir.exists()
    assert (expected_dir / "market_m1.parquet").exists()


def test_fetch_helpers_return_empty_on_http_errors(monkeypatch):
    call_counts = {"trades": 0, "book": 0, "events": 0}

    def failing_get(url, params=None, timeout=30):
        if "trades" in url:
            call_counts["trades"] += 1
        elif "/book" in url:
            call_counts["book"] += 1
        elif "clarification" in url:
            call_counts["events"] += 1
        raise DummyHTTPError("boom")

    monkeypatch.setattr(etl._session, "get", failing_get)
    monkeypatch.setattr(etl, "_sleep_with_backoff", lambda attempt: None)

    assert etl.fetch_price_history("m1") == []
    assert etl.fetch_order_book("m1") == {}
    assert etl.fetch_clarification_resolution_events("m1") == []

    assert call_counts["trades"] >= etl._MAX_ATTEMPTS
    assert call_counts["book"] >= etl._MAX_ATTEMPTS
    assert call_counts["events"] >= etl._MAX_ATTEMPTS


def test_save_market_continues_with_http_errors(tmp_path, monkeypatch):
    market = {
        "id": "m2",
        "question": "Q?",
        "outcomes": ["yes", "no"],
        "events": [{"endDate": "2024-05-10T00:00:00Z"}],
        "category": "politics",
        "createdAt": "2024-05-01T00:00:00Z",
    }

    def failing_get(url, params=None, timeout=30):
        raise DummyHTTPError("boom")

    monkeypatch.setattr(etl._session, "get", failing_get)
    monkeypatch.setattr(etl, "_sleep_with_backoff", lambda attempt: None)

    original_dumps = json.dumps
    written_records = []

    def capturing_dumps(obj, **kwargs):
        default = kwargs.pop("default", str)
        written_records.append(obj)
        return original_dumps(obj, default=default, **kwargs)

    monkeypatch.setattr(etl.json, "dumps", capturing_dumps)

    etl.save_market(market, tmp_path)

    expected_dir = tmp_path / "event_date=2024-05-10" / "category=politics"
    out_file = expected_dir / "market_m2.parquet"

    assert out_file.exists()
    assert written_records, "Expected a record to be serialized"
    record = written_records[0]
    assert record["price_history"] == []
    assert record["order_book"] is None
    assert record["clarification_resolution_events"] == []


def test_assert_partitions_warns_when_coverage_drops(tmp_path, caplog):
    today = datetime.utcnow().date()
    (
        tmp_path / f"event_date={today.isoformat()}" / "category=test"
    ).mkdir(parents=True)

    with caplog.at_level(logging.WARNING):
        etl.assert_partitions(tmp_path, days=2)

    assert any(
        record.levelno == logging.WARNING and "coverage" in record.getMessage()
        for record in caplog.records
    )
