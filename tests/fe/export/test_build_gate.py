import json
import shutil
from pathlib import Path
import sys

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
import fe.export.build as build  # noqa: E402


def test_build_aborts_when_gate_fails(monkeypatch):
    root = Path(__file__).resolve().parents[3]
    reports_dir = root / "reports"
    dist_dir = root / "dist"
    reports_dir.mkdir(exist_ok=True)
    report_path = reports_dir / "maker.json"
    report_path.write_text(json.dumps({"sample_size": 0}))

    monkeypatch.setattr(build, "approve", lambda report: False)
    try:
        with pytest.raises(SystemExit, match="maker"):
            build.build_wheel("0.0.0", ["maker"])
    finally:
        if report_path.exists():
            report_path.unlink()
        if reports_dir.exists() and not any(reports_dir.iterdir()):
            reports_dir.rmdir()
        if dist_dir.exists():
            shutil.rmtree(dist_dir)
