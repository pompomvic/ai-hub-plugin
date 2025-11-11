"""Sync dispatcher bridging API calls to workers."""

from __future__ import annotations

from uuid import UUID

from ..models.resource import SourceType
from .shopify_sync import enqueue_shopify_sync
from .wordpress_sync import enqueue_wordpress_sync


class SyncDispatchError(ValueError):
    pass


def dispatch_sync(source: SourceType, *, tenant_id: UUID, payload: dict) -> None:
    if source == "shopify":
        store_domain = payload.get("store_domain")
        access_token = payload.get("access_token")
        if not store_domain or not access_token:
            raise SyncDispatchError("Shopify sync requires store_domain and access_token")
        enqueue_shopify_sync(
            tenant_id=tenant_id,
            store_domain=store_domain,
            access_token=access_token,
            api_version=payload.get("api_version", "2024-10"),
        )
        return

    if source == "wordpress":
        base_url = payload.get("base_url")
        site_id = payload.get("site_id") or payload.get("source_site")
        if not base_url or not site_id:
            raise SyncDispatchError("WordPress sync requires base_url and site_id")
        enqueue_wordpress_sync(
            tenant_id=tenant_id,
            site_id=site_id,
            base_url=base_url,
            auth_token=payload.get("auth_token"),
            post_types=payload.get("post_types"),
        )
        return

    raise SyncDispatchError(f"Unsupported source '{source}'")
