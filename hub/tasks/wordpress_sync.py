"""WordPress ingestion workers."""

from __future__ import annotations

import logging
from typing import Any, Iterable
from uuid import UUID

import requests
from tenacity import retry, stop_after_attempt, wait_exponential

from ..adapters.wordpress import map_wordpress_post
from ..models.resource import HubResource
from . import celery_app
from .deps import get_resource_service

logger = logging.getLogger(__name__)

TASK_NAME = "hub.tasks.wordpress.sync_site"


def enqueue_wordpress_sync(*, tenant_id: UUID, site_id: str, base_url: str, auth_token: str | None = None, post_types: Iterable[str] | None = None) -> None:
    celery_app.send_task(
        TASK_NAME,
        kwargs={
            "tenant_id": str(tenant_id),
            "site_id": site_id,
            "base_url": base_url,
            "auth_token": auth_token,
            "post_types": list(post_types or ["posts", "pages"]),
        },
    )


@celery_app.task(name=TASK_NAME)
def sync_wordpress_site(tenant_id: str, site_id: str, base_url: str, auth_token: str | None = None, post_types: list[str] | None = None) -> None:
    tenant_uuid = UUID(tenant_id)
    client = WordPressClient(base_url=base_url, auth_token=auth_token)
    types = post_types or ["posts", "pages"]
    service = get_resource_service()

    batch: list[HubResource] = []
    for post_type in types:
        for post in client.iter_items(post_type):
            batch.append(map_wordpress_post(post, tenant_id=tenant_uuid, site_id=site_id))
            if len(batch) >= 100:
                service.upsert(tenant_id=tenant_uuid, resources=batch)
                batch = []

    if batch:
        service.upsert(tenant_id=tenant_uuid, resources=batch)


class WordPressClient:
    def __init__(self, *, base_url: str, auth_token: str | None):
        self.base_url = base_url.rstrip("/").rstrip("\\")
        self.session = requests.Session()
        if auth_token:
            self.session.headers.update({"Authorization": f"Bearer {auth_token}"})

    def iter_items(self, post_type: str):
        endpoint = f"{self.base_url}/wp/v2/{post_type}"
        page = 1
        while True:
            response = self._get(endpoint, params={
                "per_page": 100,
                "page": page,
                "context": "edit",
                "_embed": "wp:featuredmedia",
                "_fields": ",".join([
                    "id",
                    "slug",
                    "type",
                    "post_type",
                    "title",
                    "content",
                    "date_gmt",
                    "modified_gmt",
                    "link",
                    "meta",
                    "acf",
                    "lang",
                    "yoast_head_json",
                    "tags",
                    "_embedded",
                ]),
            })
            data = response.json()
            if not data:
                break
            for item in data:
                yield item
            total_pages = int(response.headers.get("X-WP-TotalPages", page))
            if page >= total_pages:
                break
            page += 1

    @retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=1, max=8))
    def _get(self, url: str, params: dict[str, Any]):
        response = self.session.get(url, params=params, timeout=30)
        response.raise_for_status()
        return response
