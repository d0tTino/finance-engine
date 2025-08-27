import pathlib
import sys

sys.path.append(str(pathlib.Path(__file__).resolve().parents[1]))

from fe.features.rule_objectivity import (  # noqa: E402
    batch_score,
    score_rule_objectivity,
)


def test_score_unknown():
    assert score_rule_objectivity(None) == "unknown"
    assert score_rule_objectivity("   ") == "unknown"


def test_score_clear_keyword_and_pattern():
    rule = "Outcome will be determined by official sources within 3 days"
    assert score_rule_objectivity(rule) == "clear"


def test_score_ambiguous_keyword():
    rule = "Result may be at admin's sole discretion"
    assert score_rule_objectivity(rule) == "ambiguous"


def test_score_ambiguous_pattern():
    rule = "Approx. value to be resolved"
    assert score_rule_objectivity(rule) == "ambiguous"


def test_score_numeric_sets_clear():
    rule = "The value must reach 10"
    assert score_rule_objectivity(rule) == "clear"


def test_score_default_clear():
    rule = "ordinary text without keywords"
    assert score_rule_objectivity(rule) == "clear"


def test_batch_score():
    rules = [None, "Outcome may be unknown", "Outcome will be official"]
    assert batch_score(rules) == ["unknown", "ambiguous", "clear"]
