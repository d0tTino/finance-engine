from fe.features.rule_objectivity import score_rule_objectivity


def test_none_returns_unknown():
    assert score_rule_objectivity(None) == "unknown"


def test_empty_returns_unknown():
    assert score_rule_objectivity("   ") == "unknown"


def test_clear_keyword_detection():
    rule = "Official result will be determined by data"
    assert score_rule_objectivity(rule) == "clear"


def test_ambiguous_keyword_detection():
    rule = "Outcome may be decided by admin"
    assert score_rule_objectivity(rule) == "ambiguous"


def test_digit_indicates_clear():
    assert score_rule_objectivity("Payout will be 10 dollars") == "clear"


def test_whitespace_normalisation():
    rule = "Outcome will be resolved no     later    than   Jan 1"
    assert score_rule_objectivity(rule) == "clear"


def test_regex_ambiguous_detection():
    assert score_rule_objectivity("Result is approximately 10") == "ambiguous"


def test_regex_clear_detection():
    rule = "Event shall settle within 5 days"
    assert score_rule_objectivity(rule) == "clear"
