"""Celery configuration."""

from __future__ import annotations

from celery import Celery

from ..config import get_settings


settings = get_settings()

celery_app = Celery(
    "ai_hub_universal_connector",
    broker=str(settings.redis_url),
    backend=str(settings.redis_url),
)

celery_app.conf.update(task_default_queue="hub-resources")

__all__ = ["celery_app"]
