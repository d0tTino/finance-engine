import sys
from pathlib import Path

import pandas as pd
import yaml


sys.path.append(str(Path(__file__).resolve().parents[3]))


def test_taxonomy_includes_expected_categories():
    base_dir = Path(__file__).resolve().parents[3] / "fe" / "base_rates"
    taxonomy = yaml.safe_load((base_dir / "taxonomy.yaml").read_text())
    assert "geopolitics" in taxonomy
    assert "corporate" in taxonomy
    assert "by_date" in taxonomy["geopolitics"]["horizons"]
    assert "ceo_change" in taxonomy["corporate"]["horizons"]


def test_new_categories_have_historical_data():
    base_dir = Path(__file__).resolve().parents[3] / "fe" / "base_rates"
    taxonomy = yaml.safe_load((base_dir / "taxonomy.yaml").read_text())
    data = pd.read_csv(base_dir / "historical.csv")
    for category in ["geopolitics", "corporate"]:
        assert category in taxonomy
        assert (data["category"] == category).any()
