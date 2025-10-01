from pathlib import Path
import sys

import pytest

sys.path.append(str(Path(__file__).resolve().parents[3]))
from fe.signoff.gate import ALPHA, MAX_MDD, MIN_SAMPLE, approve  # noqa: E402

CONFIG_DIR = Path(__file__).with_name("config")


def test_approve_defaults_pass():
    report = {"sample_size": MIN_SAMPLE, "max_drawdown": MAX_MDD, "p_value": ALPHA - 0.001}
    assert approve(report)


@pytest.mark.parametrize(
    "report",
    [
        {"sample_size": MIN_SAMPLE - 1, "max_drawdown": MAX_MDD, "p_value": ALPHA - 0.01},
        {"sample_size": MIN_SAMPLE, "max_drawdown": MAX_MDD + 0.01, "p_value": ALPHA - 0.01},
        {"sample_size": MIN_SAMPLE, "max_drawdown": MAX_MDD, "p_value": ALPHA + 0.01},
    ],
)
def test_approve_defaults_fail_edge_cases(report):
    assert not approve(report)


def test_approve_with_lenient_config():
    cfg = CONFIG_DIR / "lenient.json"
    report = {"sample_size": 5, "max_drawdown": 0.4, "p_value": 0.3}
    assert approve(report, config_path=cfg)


def test_approve_with_strict_config():
    cfg = CONFIG_DIR / "strict.json"
    report = {"sample_size": MIN_SAMPLE, "max_drawdown": MAX_MDD, "p_value": ALPHA - 0.01}
    assert not approve(report, config_path=cfg)


def test_approve_handles_negative_drawdown():
    failing_report = {
        "sample_size": MIN_SAMPLE,
        "max_drawdown": -0.35,
        "p_value": ALPHA - 0.01,
    }
    passing_report = {
        "sample_size": MIN_SAMPLE,
        "max_drawdown": -0.1,
        "p_value": ALPHA - 0.01,
    }

    assert not approve(failing_report)
    assert approve(passing_report)
