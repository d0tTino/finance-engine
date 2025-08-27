import pathlib
import sys
import json

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

from fe.signoff.gate import (  # noqa: E402
    ALPHA,
    MAX_MDD,
    MIN_SAMPLE,
    approve,
)


def test_approve_passes_default_thresholds():
    report = {"sample_size": MIN_SAMPLE, "max_drawdown": MAX_MDD, "p_value": ALPHA - 0.01}
    assert approve(report)


def test_approve_rejects_on_threshold():
    report = {"sample_size": MIN_SAMPLE - 1, "max_drawdown": MAX_MDD, "p_value": ALPHA - 0.01}
    assert not approve(report)


def test_approve_with_overrides():
    report = {"sample_size": 10, "max_drawdown": 0.3, "p_value": 0.04}
    assert approve(report, min_sample=5, max_mdd=0.5, alpha=0.05)


def test_approve_with_config_file(tmp_path):
    cfg = tmp_path / "cfg.json"
    cfg.write_text(json.dumps({"MIN_SAMPLE": 5, "MAX_MDD": 0.5, "ALPHA": 0.1}))
    report = {"sample_size": 6, "max_drawdown": 0.4, "p_value": 0.05}
    assert approve(report, config_path=cfg)
