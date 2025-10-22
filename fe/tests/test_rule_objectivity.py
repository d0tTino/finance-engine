"""Tests for :mod:`fe.features.rule_objectivity`."""
from __future__ import annotations

from fe.features import rule_objectivity


def test_score_rule_objectivity_clear_language() -> None:
    """Deterministic language and numbers should mark the rule as clear."""

    rule = "The official result will be determined by 3 certified sources."
    assert rule_objectivity.score_rule_objectivity(rule) == "clear"


def test_score_rule_objectivity_ambiguous_language() -> None:
    """Ambiguous keywords should downgrade the score."""

    rule = "Outcome may be decided at the admin's sole discretion."
    assert rule_objectivity.score_rule_objectivity(rule) == "ambiguous"


def test_score_rule_objectivity_unknown_language() -> None:
    """Rules without keywords fall back to the unknown category."""

    rule = "This description lacks clear qualifying language."
    assert rule_objectivity.score_rule_objectivity(rule) == "unknown"


def test_score_rule_objectivity_handles_missing_rule() -> None:
    """None or empty strings should return unknown."""

    assert rule_objectivity.score_rule_objectivity(None) == "unknown"
    assert rule_objectivity.score_rule_objectivity("   ") == "unknown"


def test_batch_score_vectorises() -> None:
    """``batch_score`` should map :func:`score_rule_objectivity` over the inputs."""

    rules = [
        "The official statement will be published publicly.",
        "Resolution may be adjusted by committee.",
        None,
    ]
    assert rule_objectivity.batch_score(rules) == ["clear", "ambiguous", "unknown"]
