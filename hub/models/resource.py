"""Pydantic models for normalized hub resources."""

from __future__ import annotations

from datetime import datetime
from typing import Dict, List, Literal, Optional
from uuid import UUID

from pydantic import BaseModel, Field

ResourceType = Literal["page", "post", "product", "collection", "asset", "category"]
SourceType = Literal["wordpress", "shopify", "drive", "manual"]


class HubResource(BaseModel):
    """Canonical representation consumed by automations and push backs."""

    id: UUID | None = None
    tenant_id: UUID
    source: SourceType
    source_site: Optional[str] = None
    source_id: str
    type: ResourceType
    slug: Optional[str] = None
    title: Optional[str] = None
    body_html: Optional[str] = None
    body_text: Optional[str] = None
    images: List[str] = Field(default_factory=list)
    price: Optional[float] = None
    currency: Optional[str] = None
    tags: List[str] = Field(default_factory=list)
    attributes: Dict[str, str] = Field(default_factory=dict)
    seo: Dict[str, str] = Field(default_factory=dict)
    locale: Optional[str] = None
    url: Optional[str] = None
    published_at: Optional[datetime] = None
    updated_at: datetime = Field(default_factory=datetime.utcnow)
    embedding: Optional[List[float]] = None

    model_config = {
        "populate_by_name": True,
        "frozen": False,
    }


class HubResourcePage(BaseModel):
    """Paginated response wrapper for API list endpoints."""

    items: List[HubResource]
    next_cursor: Optional[str] = None


class HubResourceSearch(BaseModel):
    """Response payload for similarity searches."""

    resource: HubResource
    score: float
