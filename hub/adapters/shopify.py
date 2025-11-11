"""Shopify → HubResource adapter."""

from __future__ import annotations

from datetime import datetime
from typing import Any
from uuid import UUID

from dateutil import parser as dateparser

from ..models.resource import HubResource
from ..utils.text import strip_html


def map_shopify_product(payload: dict[str, Any], *, tenant_id: UUID, store_domain: str) -> HubResource:
    description = payload.get("bodyHtml") or payload.get("body_html")
    images = payload.get("images") or []
    variants = payload.get("variants") or []
    metafields = payload.get("metafields") or []

    return HubResource(
        tenant_id=tenant_id,
        source="shopify",
        source_site=store_domain,
        source_id=str(payload.get("id")),
        type="product",
        slug=payload.get("handle"),
        title=payload.get("title"),
        body_html=description,
        body_text=strip_html(description),
        images=[img.get("url") or img.get("src") for img in images if img.get("url") or img.get("src")],
        price=_extract_price(variants),
        currency=_extract_currency(variants),
        tags=_extract_tags(payload.get("tags")),
        attributes=_map_metafields(metafields),
        seo=_derive_seo(payload.get("seo")),
        url=f"https://{store_domain}/products/{payload.get('handle')}",
        published_at=_parse_dt(payload.get("publishedAt") or payload.get("published_at")),
        updated_at=_parse_dt(payload.get("updatedAt") or payload.get("updated_at")) or datetime.utcnow(),
    )


def _extract_price(variants: list[dict[str, Any]]) -> float | None:
    if not variants:
        return None
    first = variants[0]
    price = first.get("price") or first.get("priceSet", {}).get("shopMoney", {}).get("amount")
    try:
        return float(price) if price is not None else None
    except (TypeError, ValueError):  # pragma: no cover - defensive
        return None


def _extract_currency(variants: list[dict[str, Any]]) -> str | None:
    if not variants:
        return None
    first = variants[0]
    return (
        first.get("currencyCode")
        or first.get("priceSet", {}).get("shopMoney", {}).get("currencyCode")
        or first.get("presentmentPrices", [{}])[0].get("price", {}).get("currencyCode")
    )


def _extract_tags(tags_field: Any) -> list[str]:
    if not tags_field:
        return []
    if isinstance(tags_field, list):
        return [str(tag).strip() for tag in tags_field if str(tag).strip()]
    return [tag.strip() for tag in str(tags_field).split(",") if tag.strip()]


def _map_metafields(metafields: list[dict[str, Any]]) -> dict[str, str]:
    attrs: dict[str, str] = {}
    for field in metafields:
        namespace = field.get("namespace") or "default"
        key = field.get("key") or ""
        name = f"{namespace}.{key}" if key else namespace
        value = field.get("value") or field.get("stringValue") or field.get("jsonValue")
        attrs[name] = _stringify(value)
    return attrs


def _stringify(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        return ",".join(map(_stringify, value))
    if isinstance(value, dict):
        return ",".join(f"{k}:{_stringify(v)}" for k, v in value.items())
    return str(value)


def _derive_seo(seo: dict[str, Any] | None) -> dict[str, str]:
    if not seo:
        return {}
    payload = {}
    if seo.get("title"):
        payload["title"] = seo["title"]
    if seo.get("description"):
        payload["description"] = seo["description"]
    if seo.get("canonicalUrl"):
        payload["canonical_url"] = seo["canonicalUrl"]
    return payload


def _parse_dt(value: Any) -> datetime | None:
    if not value:
        return None
    try:
        return dateparser.isoparse(value)
    except (ValueError, TypeError):  # pragma: no cover - defensive
        return None
