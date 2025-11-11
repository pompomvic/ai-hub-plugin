"""Shared task dependencies."""

from __future__ import annotations

from ..db.session import SessionFactory
from ..services.embedder import EmbeddingQueue
from ..services.resource_service import ResourceService


def get_resource_service() -> ResourceService:
    return ResourceService(session_factory=SessionFactory, embed_queue=EmbeddingQueue())
