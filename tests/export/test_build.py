from __future__ import annotations

import json
import shutil
import sys
from pathlib import Path

import pytest

sys.path.append(str(Path(__file__).resolve().parents[2]))
import fe.export.build as build


def _cleanup(paths: list[Path]) -> None:
    for path in paths:
        if path.is_file():
            path.unlink()
        elif path.is_dir() and path.exists():
            shutil.rmtree(path)


def test_build_wheel_success(monkeypatch):
    root = Path(__file__).resolve().parents[2]
    reports_dir = root / "reports"
    dist_dir = root / "dist"
    reports_dir.mkdir(exist_ok=True)
    report = {"sample_size": 100, "max_drawdown": 0.1, "p_value": 0.01}
    report_path = reports_dir / "maker.json"
    report_path.write_text(json.dumps(report))

    calls: list[dict] = []

    def fake_approve(rep):
        calls.append(rep)
        return True

    monkeypatch.setattr(build, "approve", fake_approve)

    def fake_check_call(cmd, cwd):
        (dist_dir / "polymarket_alpha-0.0.0-py3-none-any.whl").write_text("wheel")

    monkeypatch.setattr(build.subprocess, "check_call", fake_check_call)

    try:
        wheel = build.build_wheel("0.0.0", ["maker"])
        assert wheel.exists()
        assert len(calls) == 1
    finally:
        _cleanup([report_path, reports_dir, dist_dir])


def test_build_wheel_gate_failure(monkeypatch):
    root = Path(__file__).resolve().parents[2]
    reports_dir = root / "reports"
    dist_dir = root / "dist"
    reports_dir.mkdir(exist_ok=True)
    report_path = reports_dir / "maker.json"
    report_path.write_text("{}")

    monkeypatch.setattr(build, "approve", lambda report: False)

    try:
        with pytest.raises(SystemExit, match="maker"):
            build.build_wheel("0.0.0", ["maker"])
        assert not dist_dir.exists() or not any(dist_dir.glob("*.whl"))
    finally:
        _cleanup([report_path, reports_dir, dist_dir])
