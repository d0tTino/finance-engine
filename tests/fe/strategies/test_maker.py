import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[3]))

from fe.strategies.maker import make_quote


def test_extreme_liquidity_quote_is_not_inverted():
    quote = make_quote(
        yes=0.4,
        no=0.6,
        liquidity=1e6,
        skew=0.0,
        spread=0.02,
        liq_weight=0.5,
        skew_weight=0.5,
    )

    assert quote.bid <= quote.ask
    assert 0.0 <= quote.bid <= 1.0
    assert 0.0 <= quote.ask <= 1.0
