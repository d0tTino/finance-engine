"""Pydantic models for Polymarket datasets.

These schemas mirror objects described in the Polymarket documentation
(https://docs.polymarket.com/) and include fields used for Parquet
partitioning.
"""

from __future__ import annotations

from datetime import date, datetime
from typing import List, Optional, Tuple

from pydantic import BaseModel, Field


class PriceHistory(BaseModel):
    """Price history for a market.

    See: https://docs.polymarket.com/data/price-history
    """

    market_id: str = Field(..., description="Polymarket market identifier")
    timestamp: datetime = Field(..., description="Timestamp of the quoted price")
    price: float = Field(..., description="Price of the outcome")
    event_date: date = Field(..., description="Event date used for partitioning")
    category: str = Field(..., description="Market category used for partitioning")


class OrderBookSnapshot(BaseModel):
    """Order book snapshot for a market.

    See: https://docs.polymarket.com/data/order-books
    """

    market_id: str = Field(..., description="Polymarket market identifier")
    timestamp: datetime = Field(..., description="Snapshot timestamp")
    bids: List[Tuple[float, float]] = Field(
        ..., description="List of [price, quantity] bid levels"
    )
    asks: List[Tuple[float, float]] = Field(
        ..., description="List of [price, quantity] ask levels"
    )
    event_date: date = Field(..., description="Event date used for partitioning")
    category: str = Field(..., description="Market category used for partitioning")


class MarketMetadata(BaseModel):
    """Metadata describing a market.

    See: https://docs.polymarket.com/data/markets
    """

    market_id: str = Field(..., description="Polymarket market identifier")
    question: str = Field(..., description="Market question or title")
    outcomes: List[str] = Field(..., description="Possible market outcomes")
    created_at: datetime = Field(..., description="Market creation timestamp")
    event_date: date = Field(..., description="Event date used for partitioning")
    category: str = Field(..., description="Market category used for partitioning")


class ResolutionEvent(BaseModel):
    """Resolution information for a market.

    See: https://docs.polymarket.com/data/resolution
    """

    market_id: str = Field(..., description="Polymarket market identifier")
    resolution_time: datetime = Field(..., description="Timestamp of resolution")
    status: str = Field(..., description="Resolution status or outcome")
    outcome: Optional[str] = Field(None, description="Winning outcome, if any")
    event_date: date = Field(..., description="Event date used for partitioning")
    category: str = Field(..., description="Market category used for partitioning")
