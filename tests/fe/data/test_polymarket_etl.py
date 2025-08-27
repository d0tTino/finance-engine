import sys
from datetime import datetime, timedelta
from pathlib import Path
import json

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.data.polymarket import etl  # noqa: E402


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
        etl, "fetch_price_history", lambda market_id: [{"timestamp": "2024-05-10T00:00:00Z", "price": 0.5}]
    )
    monkeypatch.setattr(
        etl,
        "fetch_order_book",
        lambda market_id: {"timestamp": "2024-05-10T00:00:00Z", "bids": [], "asks": []},
    )
    monkeypatch.setattr(
        etl,
        "fetch_clarification_resolution_events",
        lambda market_id: [
            {"timestamp": "2024-05-10T00:00:00Z", "event_type": "resolution", "message": "done"}
        ],
    )

    original_dumps = json.dumps
    monkeypatch.setattr(etl.json, "dumps", lambda obj: original_dumps(obj, default=str))

    etl.save_market(market, tmp_path)

    expected_dir = tmp_path / "event_date=2024-05-10" / "category=politics"
    assert expected_dir.exists()
    assert (expected_dir / "market_m1.parquet").exists()


def test_assert_partitions_detects_missing_days(tmp_path):
    today = datetime.utcnow().date()
    (tmp_path / f"event_date={today.isoformat()}" / "category=test").mkdir(parents=True)

    with pytest.raises(AssertionError) as exc:
        etl.assert_partitions(tmp_path, days=2)

    missing_day = (today - timedelta(days=1)).isoformat()
    assert missing_day in str(exc.value)
