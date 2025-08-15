import sys
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
