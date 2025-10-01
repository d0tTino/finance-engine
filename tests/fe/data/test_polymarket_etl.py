import logging
import sys
import types
from datetime import datetime, timedelta
from pathlib import Path
import json

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))

if "pandas" not in sys.modules:  # pragma: no cover - test shim for optional dependency
    fake_pandas = types.ModuleType("pandas")

    class _FakeDataFrame:
        def __init__(self, *args, **kwargs):
            self._args = args
            self._kwargs = kwargs

        def to_parquet(self, path, index=False):
            Path(path).write_bytes(b"fake-parquet")

    fake_pandas.DataFrame = _FakeDataFrame
    sys.modules["pandas"] = fake_pandas

if "requests" not in sys.modules:  # pragma: no cover - test shim for optional dependency
    fake_requests = types.ModuleType("requests")

    class _FakeSession:
        def __init__(self):
            self.mounted = {}

        def mount(self, prefix, adapter):
            self.mounted[prefix] = adapter

        def get(self, url, params=None, timeout=None):  # pragma: no cover - not used directly
            raise NotImplementedError("Fake session does not perform HTTP calls")

    fake_requests.Session = _FakeSession
    sys.modules["requests"] = fake_requests

    fake_requests_adapters = types.ModuleType("requests.adapters")

    class _FakeHTTPAdapter:
        def __init__(self, max_retries=None):
            self.max_retries = max_retries

    fake_requests_adapters.HTTPAdapter = _FakeHTTPAdapter
    sys.modules["requests.adapters"] = fake_requests_adapters

if "urllib3" not in sys.modules:  # pragma: no cover - test shim for optional dependency
    fake_urllib3 = types.ModuleType("urllib3")
    fake_urllib3_util = types.ModuleType("urllib3.util")
    fake_retry_module = types.ModuleType("urllib3.util.retry")

    class _FakeRetry:
        def __init__(self, **kwargs):
            self.kwargs = kwargs

    fake_retry_module.Retry = _FakeRetry
    fake_urllib3.util = fake_urllib3_util
    fake_urllib3_util.retry = fake_retry_module

    sys.modules["urllib3"] = fake_urllib3
    sys.modules["urllib3.util"] = fake_urllib3_util
    sys.modules["urllib3.util.retry"] = fake_retry_module

if "pydantic" not in sys.modules:  # pragma: no cover - test shim for optional dependency
    fake_pydantic = types.ModuleType("pydantic")

    class _FakeBaseModel:
        def __init__(self, **kwargs):
            self._data = dict(kwargs)

        def model_dump(self):
            return dict(self._data)

    def _fake_field(default=None, **kwargs):
        return default

    fake_pydantic.BaseModel = _FakeBaseModel
    fake_pydantic.Field = _fake_field
    sys.modules["pydantic"] = fake_pydantic

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
    assert written_records, "Expected order book payload to be serialized"
    record = written_records[0]
    assert record["order_book"] is not None
    assert record["order_book"]["bids"] == []
    assert record["order_book"]["asks"] == []


def test_save_market_handles_missing_order_book_side(tmp_path, monkeypatch):
    market = {
        "id": "m_partial",
        "question": "Q?",
        "outcomes": ["yes", "no"],
        "events": [{"endDate": "2024-05-10T00:00:00Z"}],
        "category": "politics",
        "createdAt": "2024-05-01T00:00:00Z",
    }

    monkeypatch.setattr(etl, "fetch_price_history", lambda market_id: [])
    monkeypatch.setattr(
        etl,
        "fetch_order_book",
        lambda market_id: {
            "timestamp": "2024-05-10T00:00:00Z",
            "asks": [[0.55, 100.0]],
        },
    )
    monkeypatch.setattr(etl, "fetch_clarification_resolution_events", lambda market_id: [])

    original_dumps = json.dumps
    written_records = []

    def capturing_dumps(obj, **kwargs):
        default = kwargs.pop("default", str)
        written_records.append(obj)
        return original_dumps(obj, default=default, **kwargs)

    monkeypatch.setattr(etl.json, "dumps", capturing_dumps)

    etl.save_market(market, tmp_path)

    out_file = (
        tmp_path
        / "event_date=2024-05-10"
        / "category=politics"
        / "market_m_partial.parquet"
    )
    assert out_file.exists()
    assert written_records, "Expected serialized payload"
    record = written_records[0]
    assert record["order_book"] is not None
    assert record["order_book"]["bids"] == []
    assert record["order_book"]["asks"] == [[0.55, 100.0]]


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
