"""Embedding worker tasks."""

from __future__ import annotations

import logging
import uuid
from typing import Iterable

from sqlalchemy import select

from ..db.models import HubResourceRow
from ..db.session import SessionFactory, apply_tenant_rls
from ..services.embedding_provider import get_embedding_provider
from . import celery_app

logger = logging.getLogger(__name__)
provider = get_embedding_provider()


@celery_app.task(name="hub.tasks.embeddings.enqueue_embedding")
def enqueue_embedding(tenant_id: str, resource_ids: Iterable[str]) -> None:
    session = SessionFactory()
    try:
        apply_tenant_rls(session, tenant_id)
        uuids = [uuid.UUID(rid) for rid in resource_ids]
        if not uuids:
            return
        stmt = select(HubResourceRow).where(HubResourceRow.id.in_(uuids))
        rows = session.execute(stmt).scalars().all()
        for row in rows:
            text = row.body_text or row.body_html
            if not text:
                row.embedding = None
                continue
            try:
                row.embedding = provider.embed(text)
            except Exception as exc:  # pragma: no cover - network errors fall back silently
                logger.warning("Embedding generation failed for %s: %s", row.id, exc)
                row.embedding = None
        session.commit()
    finally:
        session.close()
