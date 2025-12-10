"""Tests for :mod:`fe.data.polymarket.etl`."""
from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Any

import pandas as pd
import pytest

from fe.data.polymarket import etl


def test_save_market_creates_partitioned_output(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    sample_market: dict[str, Any],
) -> None:
    """``save_market`` should write a single-row parquet file in the partition."""

    price_payload = [
        {"timestamp": "2024-01-05T00:00:00Z", "price": 0.42},
        {"timestamp": "2024-01-06T00:00:00Z", "price": 0.58},
        {"timestamp": "2024-01-06T06:00:00Z"},  # missing price ignored
        {"t": "2024-01-06T12:00:00Z", "p": 0.61},  # alternate keys
    ]
    order_book_payload = {
        "timestamp": "2024-01-05T00:00:00Z",
        "bids": [[0.45, 120.0], [0.40, 30.0]],
        "asks": [[0.55, 80.0]],
    }
    events_payload = [
        {
            "timestamp": "2024-01-04T00:00:00Z",
            "event_type": "clarification",
            "message": "Updated market rules.",
        },
        {"timestamp": "2024-01-05T00:00:00Z", "event_type": "clarification"},
    ]

    monkeypatch.setattr(etl, "fetch_price_history", lambda market_id: price_payload)
    monkeypatch.setattr(etl, "fetch_order_book", lambda market_id: order_book_payload)
    monkeypatch.setattr(
        etl, "fetch_clarification_resolution_events", lambda market_id: events_payload
    )

    captured: dict[str, pd.DataFrame] = {}

    def fake_to_parquet(self: pd.DataFrame, path: Path | str, index: bool = False) -> None:
        captured["df"] = self.copy()
        Path(path).write_text(self.to_json(orient="records"))

    monkeypatch.setattr(pd.DataFrame, "to_parquet", fake_to_parquet, raising=False)

    etl.save_market(sample_market, tmp_path)

    expected_dir = tmp_path / "event_date=2024-01-07" / "category=politics"
    out_file = expected_dir / "market_market-123.parquet"
    assert out_file.exists(), "partitioned parquet file should be created"

    payload = json.loads(out_file.read_text())
    assert len(payload) == 1
    stored = json.loads(payload[0]["data"])

    assert stored["market"]["market_id"] == "market-123"
    # Two valid entries plus one using alternate keys should survive.
    assert len(stored["price_history"]) == 3
    assert stored["price_history"][0]["price"] == 0.42
    assert stored["order_book"]["bids"] == order_book_payload["bids"]
    assert stored["clarification_resolution_events"][0]["event_type"] == "clarification"

    # Ensure the intermediate DataFrame matches expectations
    assert "df" in captured and len(captured["df"]) == 1


def test_fetch_price_history_handles_invalid_payload(monkeypatch: pytest.MonkeyPatch) -> None:
    """Invalid payloads should be retried and ultimately return an empty list."""

    call_count = {"value": 0}

    def fake_get(url: str, params: dict[str, Any] | None = None) -> dict[str, str]:
        call_count["value"] += 1
        return {"unexpected": "payload"}

    monkeypatch.setattr(etl, "_get", fake_get)
    monkeypatch.setattr(etl, "_sleep_with_backoff", lambda attempt: None)

    result = etl.fetch_price_history("market-123")

    assert result == []
    assert call_count["value"] >= 1


def test_assert_partitions_enforces_minimum_coverage(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    """``assert_partitions`` should raise when coverage drops below the threshold."""

    class FixedDateTime(datetime):
        @classmethod
        def utcnow(cls) -> "FixedDateTime":
            return cls(2024, 1, 10, 0, 0, 0)

    monkeypatch.setattr(etl, "datetime", FixedDateTime)

    for day in ("2024-01-10", "2024-01-09", "2024-01-07"):
        market_dir = tmp_path / f"event_date={day}" / "category=politics"
        market_dir.mkdir(parents=True, exist_ok=True)
        (market_dir / "placeholder.txt").write_text("data")

    with pytest.raises(etl.PartitionCoverageError):
        etl.assert_partitions(tmp_path, days=5, min_coverage=0.8)


def test_prune_logs_respects_retention(tmp_path: Path) -> None:
    old_log = tmp_path / "run1.log"
    fresh_log = tmp_path / "run2.log"
    old_log.write_text("old")
    fresh_log.write_text("fresh")

    cutoff = datetime.utcnow().timestamp() - (5 * 86400)
    os.utime(old_log, (cutoff, cutoff))

    removed = etl.prune_logs(tmp_path, retention_days=3)

    assert old_log in removed
    assert fresh_log.exists()
    assert fresh_log not in removed
