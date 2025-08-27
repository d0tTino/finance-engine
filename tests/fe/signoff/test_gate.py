import sys
import json
from pathlib import Path

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.signoff.gate import approve  # noqa: E402


def test_gate_passes_when_all_thresholds_met():
    report = {"sample_size": 50, "max_drawdown": 0.1, "p_value": 0.01}
    assert approve(report)


@pytest.mark.parametrize(
    "report",
    [
        {"sample_size": 10, "max_drawdown": 0.1, "p_value": 0.01},
        {"sample_size": 50, "max_drawdown": 0.3, "p_value": 0.01},
        {"sample_size": 50, "max_drawdown": 0.1, "p_value": 0.2},
    ],
)
def test_gate_rejects_when_any_threshold_fails(report):
    assert not approve(report)


def test_gate_respects_parameter_overrides():
    report = {"sample_size": 50, "max_drawdown": 0.1, "p_value": 0.01}
    assert not approve(report, min_sample=100)


def test_gate_respects_config_file(tmp_path):
    report = {"sample_size": 50, "max_drawdown": 0.1, "p_value": 0.01}
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps({"MAX_MDD": 0.05}))
    assert not approve(report, config_path=cfg)


def test_gate_uses_default_config():
    root = Path(__file__).resolve().parents[3]
    cfg = json.loads((root / "fe" / "signoff" / "config.json").read_text())

    failing = {
        "sample_size": cfg["MIN_SAMPLE"] - 1,
        "max_drawdown": cfg["MAX_MDD"] + 0.01,
        "p_value": cfg["ALPHA"] + 0.01,
    }
    assert not approve(failing)

    passing = {
        "sample_size": cfg["MIN_SAMPLE"],
        "max_drawdown": cfg["MAX_MDD"],
        "p_value": cfg["ALPHA"] - 0.01,
    }
    assert approve(passing)
