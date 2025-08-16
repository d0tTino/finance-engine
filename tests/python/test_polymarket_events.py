import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[2]))

import json  # noqa: E402
import pandas as pd  # noqa: E402
from fe.data.polymarket import etl  # noqa: E402


def test_save_market_writes_clarification_events(tmp_path, monkeypatch):
    market = {
        "id": "123",
        "endDate": "2020-11-04T00:00:00Z",
        "category": "politics",
    }

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
            return []
        if url.endswith("/book"):
            return {}
        raise AssertionError(f"unexpected url {url}")

    monkeypatch.setattr(etl, "_get", fake_get)
    monkeypatch.setattr(etl, "OUTPUT_DIR", tmp_path)

    etl.save_market(market)

    out_dir = tmp_path / "event_date=2020-11-04" / "category=politics"
    parquet_files = list(out_dir.glob("market_123.parquet"))
    assert parquet_files

    df = pd.read_parquet(parquet_files[0])
    record = df["data"].map(json.loads).iloc[0]
    assert record["clarification_resolution_events"] == events_payload
