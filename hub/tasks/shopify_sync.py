"""Celery worker to ingest Shopify resources."""

from __future__ import annotations

import logging
from typing import Any, Generator, Iterable
from uuid import UUID

import requests
from tenacity import retry, stop_after_attempt, wait_exponential

from ..adapters.shopify import map_shopify_product
from ..models.resource import HubResource
from . import celery_app
from .deps import get_resource_service

logger = logging.getLogger(__name__)

TASK_NAME = "hub.tasks.shopify.sync_store"


def enqueue_shopify_sync(*, tenant_id: UUID, store_domain: str, access_token: str, api_version: str = "2024-10") -> None:
    celery_app.send_task(
        TASK_NAME,
        kwargs={
            "tenant_id": str(tenant_id),
            "store_domain": store_domain,
            "access_token": access_token,
            "api_version": api_version,
        },
    )


@celery_app.task(name=TASK_NAME)
def sync_shopify_store(tenant_id: str, store_domain: str, access_token: str, api_version: str = "2024-10") -> None:
    tenant_uuid = UUID(tenant_id)
    client = ShopifyClient(store_domain=store_domain, access_token=access_token, api_version=api_version)
    service = get_resource_service()

    batch: list[HubResource] = []
    for product in client.iter_products():
        batch.append(map_shopify_product(product, tenant_id=tenant_uuid, store_domain=store_domain))
        if len(batch) >= 100:
            service.upsert(tenant_id=tenant_uuid, resources=batch)
            batch = []

    if batch:
        service.upsert(tenant_id=tenant_uuid, resources=batch)


class ShopifyClient:
    API_PATH = "admin/api"

    def __init__(self, *, store_domain: str, access_token: str, api_version: str):
        self.store_domain = store_domain
        self.session = requests.Session()
        self.session.headers.update(
            {
                "X-Shopify-Access-Token": access_token,
                "Content-Type": "application/json",
            }
        )
        self.api_version = api_version

    def iter_products(self) -> Generator[dict[str, Any], None, None]:
        cursor: str | None = None
        while True:
            data = self._post_graphql(
                query=_PRODUCTS_QUERY,
                variables={"cursor": cursor},
            )
            products = data.get("data", {}).get("products", {})
            nodes = products.get("nodes", [])
            for node in nodes:
                yield _normalize_product_node(node)
            page_info = products.get("pageInfo", {})
            if not page_info.get("hasNextPage"):
                break
            cursor = page_info.get("endCursor")

    @retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=1, max=8))
    def _post_graphql(self, *, query: str, variables: dict[str, Any]) -> dict[str, Any]:
        url = f"https://{self.store_domain}/{self.API_PATH}/{self.api_version}/graphql.json"
        response = self.session.post(url, json={"query": query, "variables": variables}, timeout=30)
        response.raise_for_status()
        data = response.json()
        if "errors" in data:
            logger.error("Shopify GraphQL error: %s", data["errors"])
            raise RuntimeError("Shopify GraphQL error")
        return data


def _normalize_product_node(node: dict[str, Any]) -> dict[str, Any]:
    images = [img for img in node.get("images", {}).get("nodes", [])]
    variants: list[dict[str, Any]] = []
    for variant in node.get("variants", {}).get("nodes", []):
        presentment = [edge.get("node") for edge in variant.get("presentmentPrices", {}).get("edges", []) if edge.get("node")]
        variant = {**variant, "presentmentPrices": presentment}
        variants.append(variant)
    metafields = [edge.get("node") for edge in node.get("metafields", {}).get("edges", []) if edge.get("node")]

    return {
        **node,
        "images": images,
        "variants": variants,
        "metafields": metafields,
    }


_PRODUCTS_QUERY = """
query Products($cursor: String) {
  products(first: 50, after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      bodyHtml
      tags
      seo { title description canonicalUrl }
      publishedAt
      updatedAt
      images(first: 10) {
        nodes { url src }
      }
      variants(first: 1) {
        nodes {
          id
          price
          currencyCode
          priceSet { shopMoney { amount currencyCode } }
          presentmentPrices(first: 1) {
            edges { node { price { amount currencyCode } } }
          }
        }
      }
      metafields(first: 50) {
        edges {
          node {
            namespace
            key
            value
          }
        }
      }
    }
  }
}
"""
