import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[2]))

from fe.data.polymarket import etl  # noqa: E402


def test_get_retries():
    class Handler(BaseHTTPRequestHandler):
        attempts = 0

        def do_GET(self):
            Handler.attempts += 1
            if Handler.attempts < 3:
                self.send_response(500)
                self.end_headers()
            else:
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b"{\"ok\": true}")

        def log_message(self, format, *args):  # pragma: no cover
            pass

    server = HTTPServer(("localhost", 0), Handler)
    thread = threading.Thread(target=server.serve_forever)
    thread.daemon = True
    thread.start()
    try:
        url = f"http://localhost:{server.server_port}/test"
        result = etl._get(url)
        assert result == {"ok": True}
        assert Handler.attempts == 3
    finally:
        server.shutdown()
        thread.join()


def test_save_market_partition_paths(tmp_path, monkeypatch):
    market = {
        "id": "1",
        "endDate": "2020-11-04T00:00:00Z",
        "category": "politics",
        "question": "Will it rain?",
        "outcomes": ["Yes", "No"],
        "createdAt": "2020-01-01T00:00:00Z",
    }
    monkeypatch.setattr(etl, "fetch_price_history", lambda _id: [])
    monkeypatch.setattr(etl, "fetch_order_book", lambda _id: {})
    monkeypatch.setattr(etl, "fetch_clarification_resolution_events", lambda _id: [])
    etl.save_market(market, output_dir=tmp_path)
    expected = (
        tmp_path
        / "event_date=2020-11-04"
        / "category=politics"
        / "market_1.parquet"
    )
    assert expected.exists()
