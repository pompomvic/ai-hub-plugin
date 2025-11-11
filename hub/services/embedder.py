"""Embedding queue helpers."""

from __future__ import annotations

import uuid
from dataclasses import dataclass
from typing import Iterable

from ..tasks import celery_app


@dataclass(slots=True)
class EmbeddingQueue:
    task_name: str = "hub.tasks.embeddings.enqueue_embedding"

    def enqueue(self, tenant_id: uuid.UUID, resource_ids: Iterable[uuid.UUID]) -> None:
        ids = [str(rid) for rid in resource_ids]
        if not ids:
            return
        celery_app.send_task(self.task_name, kwargs={"tenant_id": str(tenant_id), "resource_ids": ids})
