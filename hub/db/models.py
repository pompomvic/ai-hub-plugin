"""SQLAlchemy models mirroring canonical hub entities."""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import ARRAY, JSON, TIMESTAMP, UniqueConstraint, Boolean
from sqlalchemy import String, Text
from sqlalchemy.dialects.postgresql import UUID as PGUUID
from sqlalchemy.orm import Mapped, mapped_column
from sqlalchemy.sql import func

from pgvector.sqlalchemy import Vector

from .base import Base


class HubResourceRow(Base):
    """Normalized resource row stored in Postgres (pgvector-enabled)."""

    __tablename__ = "hub_resources"

    id: Mapped[uuid.UUID] = mapped_column(PGUUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    tenant_id: Mapped[uuid.UUID] = mapped_column(PGUUID(as_uuid=True), index=True)
    source: Mapped[str] = mapped_column(String(32))
    source_site: Mapped[str | None] = mapped_column(String(255), nullable=True)
    source_id: Mapped[str] = mapped_column(String(128))
    type: Mapped[str] = mapped_column(String(32), index=True)
    slug: Mapped[str | None] = mapped_column(String(255), nullable=True)
    title: Mapped[str | None] = mapped_column(String(512), nullable=True)
    body_html: Mapped[str | None] = mapped_column(Text, nullable=True)
    body_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    images: Mapped[list[str]] = mapped_column(ARRAY(String(2048)), default=list)
    price: Mapped[float | None] = mapped_column(nullable=True)
    currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    tags: Mapped[list[str]] = mapped_column(ARRAY(String(128)), default=list)
    attributes: Mapped[dict] = mapped_column(JSON, default=dict)
    seo: Mapped[dict] = mapped_column(JSON, default=dict)
    locale: Mapped[str | None] = mapped_column(String(16), nullable=True)
    url: Mapped[str | None] = mapped_column(String(2048), nullable=True)
    published_at: Mapped[datetime | None] = mapped_column(TIMESTAMP(timezone=True), nullable=True)
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), server_default=func.now(), onupdate=func.now()
    )
    embedding: Mapped[list[float] | None] = mapped_column(Vector(1536), nullable=True)

    __table_args__ = (
        UniqueConstraint("tenant_id", "source", "source_site", "source_id", name="uq_resource_origin"),
    )


class SiteIntegrationRow(Base):
    """Per-site instrumentation settings scoped by tenant."""

    __tablename__ = "site_integrations"

    id: Mapped[uuid.UUID] = mapped_column(PGUUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    tenant_id: Mapped[uuid.UUID] = mapped_column(PGUUID(as_uuid=True), index=True)
    site_id: Mapped[str] = mapped_column(String(128))
    ga_measurement_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    gtm_container_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    conversion_event: Mapped[str | None] = mapped_column(String(64), nullable=True)
    consent_cookie_name: Mapped[str | None] = mapped_column(String(128), nullable=True)
    consent_opt_out_value: Mapped[str | None] = mapped_column(String(32), nullable=True)
    session_replay_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    session_replay_project_key: Mapped[str | None] = mapped_column(String(128), nullable=True)
    session_replay_host: Mapped[str | None] = mapped_column(String(255), nullable=True)
    session_replay_mask_selectors: Mapped[list[str]] = mapped_column(ARRAY(String(255)), default=list)
    feedback_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    feedback_widget_url: Mapped[str | None] = mapped_column(String(2048), nullable=True)
    feedback_project_key: Mapped[str | None] = mapped_column(String(128), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        UniqueConstraint("tenant_id", "site_id", name="uq_site_integration_tenant_site"),
    )
