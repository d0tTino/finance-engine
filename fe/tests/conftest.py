"""Shared fixtures for finance-engine tests."""
from __future__ import annotations

import pytest


@pytest.fixture
def sample_market() -> dict:
    """Return a representative Polymarket market payload."""
    return {
        "id": "market-123",
        "question": "Will the sample event occur?",
        "outcomes": ["Yes", "No"],
        "createdAt": "2024-01-01T12:00:00Z",
        "endDate": "2024-01-07T00:00:00Z",
        "category": "politics",
        "resolutionDescription": "Sample resolution description.",
        "resolutionSources": ["https://example.com/source"],
        "events": [
            {
                "endDate": "2024-01-07T00:00:00Z",
            }
        ],
    }
