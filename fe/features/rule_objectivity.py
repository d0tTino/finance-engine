"""Heuristics for scoring clarity of Polymarket rules."""
from __future__ import annotations

from typing import Iterable, Optional
import re

CLEAR_KEYWORDS = {
    "official",
    "exact",
    "exactly",
    "no later than",
    "based on",
    "publicly",
    "will be",
    "determined by",
    "shall",
    "must",
    "within",
}
AMBIGUOUS_KEYWORDS = {
    "ambiguity",
    "sole discretion",
    "discretion",
    "tbd",
    "unknown",
    "may",
    "might",
    "could",
    "subject to",
    "admin",
    "committee",
    "approximately",
    "approx",
    "around",
    "roughly",
    "likely",
    "probably",
}

CLEAR_PATTERNS = [
    re.compile(r"within\s+\d+\s+(?:day|hour|minute|week|month)s?"),
]

AMBIGUOUS_PATTERNS = [
    re.compile(r"\bapprox(?:\.|imately)?\b"),
    re.compile(r"\broughly\b"),
]


def score_rule_objectivity(rule: Optional[str]) -> str:
    """Return objectivity score for ``rule``.

    The score is one of ``"clear"``, ``"ambiguous"``, or ``"unknown"``.  A
    rule is considered clear when it contains strong determinate language or
    numeric thresholds.  It is considered ambiguous when it contains terms
    suggesting discretion or uncertainty.  Missing or empty rules return
    ``"unknown"``.
    """
    if not rule or not rule.strip():
        return "unknown"

    text = " ".join(rule.split()).lower()
    ambiguous = any(kw in text for kw in AMBIGUOUS_KEYWORDS) or any(
        pattern.search(text) for pattern in AMBIGUOUS_PATTERNS
    )
    clear = any(kw in text for kw in CLEAR_KEYWORDS) or any(
        pattern.search(text) for pattern in CLEAR_PATTERNS
    )
    if re.search(r"\d", text):
        clear = True

    if ambiguous:
        return "ambiguous"
    if clear:
        return "clear"
    # Default to "clear" when text exists but heuristics find nothing
    return "clear"


def batch_score(rules: Iterable[Optional[str]]) -> list[str]:
    """Vectorised wrapper around :func:`score_rule_objectivity`."""
    return [score_rule_objectivity(rule) for rule in rules]
