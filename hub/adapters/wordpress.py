"""WordPress → HubResource mapper."""

from __future__ import annotations

from datetime import datetime
from typing import Any
from uuid import UUID

from dateutil import parser as dateparser

from ..models.resource import HubResource
from ..utils.text import strip_html

WORDPRESS_TYPE_MAP = {
    "post": "post",
    "page": "page",
    "product": "product",  # WooCommerce
}


def map_wordpress_post(payload: dict[str, Any], *, tenant_id: UUID, site_id: str) -> HubResource:
    content = (payload.get("content") or {}).get("rendered")
    yoast = payload.get("yoast_head_json") or {}
    meta = payload.get("meta") or {}
    acf = payload.get("acf") or {}

    attributes = {**_stringify(meta), **_stringify(acf, prefix="acf.")}

    seo = {
        "title": yoast.get("title"),
        "description": yoast.get("description"),
        "schema": yoast.get("schema"),
    }

    return HubResource(
        tenant_id=tenant_id,
        source="wordpress",
        source_site=site_id,
        source_id=str(payload.get("id")),
        type=_infer_type(payload.get("type") or payload.get("post_type")),
        slug=payload.get("slug"),
        title=_maybe_render(payload.get("title")),
        body_html=content,
        body_text=strip_html(content),
        images=_extract_images(payload),
        tags=_extract_tags(payload),
        attributes=attributes,
        seo={k: v for k, v in seo.items() if v},
        locale=payload.get("lang"),
        url=payload.get("link"),
        published_at=_parse_dt(payload.get("date_gmt")),
        updated_at=_parse_dt(payload.get("modified_gmt")) or datetime.utcnow(),
    )


def _infer_type(post_type: str | None) -> str:
    if not post_type:
        return "post"
    return WORDPRESS_TYPE_MAP.get(post_type, "post")


def _extract_tags(payload: dict[str, Any]) -> list[str]:
    if "tags" in payload and isinstance(payload["tags"], list):
        return [str(tag) for tag in payload["tags"]]
    return []


def _extract_images(payload: dict[str, Any]) -> list[str]:
    embedded = payload.get("_embedded") or {}
    featured = embedded.get("wp:featuredmedia") or []
    urls: list[str] = []
    for media in featured:
        source_url = media.get("source_url")
        if source_url:
            urls.append(source_url)
    return urls


def _stringify(values: dict, *, prefix: str = "") -> dict[str, str]:
    normalized: dict[str, str] = {}
    for key, value in values.items():
        final_key = f"{prefix}{key}" if prefix else key
        normalized[final_key] = _stringify_value(value)
    return normalized


def _stringify_value(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        return ",".join(map(_stringify_value, value))
    if isinstance(value, dict):
        return ",".join(f"{k}:{_stringify_value(v)}" for k, v in value.items())
    return str(value)


def _parse_dt(value: Any) -> datetime | None:
    if not value:
        return None
    try:
        return dateparser.isoparse(value)
    except (ValueError, TypeError):  # pragma: no cover - defensive
        return None


def _maybe_render(field: Any) -> str | None:
    if isinstance(field, dict):
        return field.get("rendered")
    return field
