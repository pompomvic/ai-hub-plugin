Universal Connector Blueprint
=============================

Status
------
- **Date:** November 8, 2025
- **Owner:** AI Hub Platform
- **Scope:** Backend aggregation service that normalizes any commerce/CMS resource into a shared schema so downstream automations stay platform-agnostic.

Problem Statement
-----------------
Each surface (WordPress, Shopify, Google Drive, “manual” uploads) exposes its own data model and API contract. AI Hub automations—SEO rewrites, NL → content edits, bulk tagging, etc.—currently understand only the WordPress schema. This forces one-off pipelines per platform and prevents us from reusing automation workflows. We need a neutral representation that sits between adapters (platform-specific) and automations (platform-agnostic).

Proposed Architecture
---------------------
1. **HubResource schema (authoritative source):** All platforms upsert normalized rows into `hub_resources` (Postgres + `pgvector`).
2. **Adapters:** Small mappers convert platform payloads ↔ `HubResource`.
3. **Automation runtime:** Consumes and updates only `HubResource`, never raw platform payloads. Push-backs reuse adapters to apply edits on the origin platform.
4. **APIs & Workers:** FastAPI exposes read/search endpoints; Celery workers own pull-sync, enrichment (body text, embeddings), and optional push-backs.

Schema Definition
-----------------

### Pydantic (v2) model

```python
# hub/models/resource.py
from datetime import datetime
from typing import Dict, List, Literal, Optional
from uuid import UUID

from pydantic import BaseModel, Field, HttpUrl

ResourceType = Literal["page", "post", "product", "collection", "asset", "category"]
SourceType = Literal["wordpress", "shopify", "drive", "manual"]


class HubResource(BaseModel):
    id: UUID
    tenant_id: UUID
    source: SourceType
    source_site: Optional[str] = None  # e.g., WP blog ID, Shopify domain
    source_id: str
    type: ResourceType
    slug: Optional[str] = None
    title: Optional[str] = None
    body_html: Optional[str] = None
    body_text: Optional[str] = None
    images: List[HttpUrl] = []
    price: Optional[float] = None
    currency: Optional[str] = None
    tags: List[str] = []
    attributes: Dict[str, str] = {}
    seo: Dict[str, str] = {}
    locale: Optional[str] = None
    url: Optional[HttpUrl] = None
    published_at: Optional[datetime] = None
    updated_at: datetime = Field(default_factory=datetime.utcnow)
    embedding: Optional[List[float]] = None  # hydrated post-ingest
```

### SQLAlchemy model (pgvector-aware)

```python
# hub/db/models.py
from sqlalchemy import JSON, TIMESTAMP, ARRAY, Float, String
from sqlalchemy.dialects.postgresql import UUID, TEXT
from sqlalchemy.orm import Mapped, mapped_column
from sqlalchemy.sql import func

from pgvector.sqlalchemy import Vector

class HubResourceRow(Base):
    __tablename__ = "hub_resources"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True)
    tenant_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), index=True)
    source: Mapped[str] = mapped_column(String(32))
    source_site: Mapped[str | None]
    source_id: Mapped[str] = mapped_column(String(128))
    type: Mapped[str] = mapped_column(String(32), index=True)
    slug: Mapped[str | None] = mapped_column(String(255))
    title: Mapped[str | None] = mapped_column(String(512))
    body_html: Mapped[str | None] = mapped_column(TEXT)
    body_text: Mapped[str | None] = mapped_column(TEXT)
    images: Mapped[list[str]] = mapped_column(ARRAY(String(2048)))
    price: Mapped[float | None] = mapped_column(Float)
    currency: Mapped[str | None] = mapped_column(String(8))
    tags: Mapped[list[str]] = mapped_column(ARRAY(String(128)))
    attributes: Mapped[dict] = mapped_column(JSON)
    seo: Mapped[dict] = mapped_column(JSON)
    locale: Mapped[str | None] = mapped_column(String(16))
    url: Mapped[str | None] = mapped_column(String(2048))
    published_at: Mapped[datetime | None]
    updated_at: Mapped[datetime] = mapped_column(TIMESTAMP(timezone=True), server_default=func.now())
    embedding: Mapped[list[float] | None] = mapped_column(Vector(1536))

    __table_args__ = (
        UniqueConstraint("tenant_id", "source", "source_site", "source_id", name="uq_resource_origin"),
    )
```

### DDL w/ pgvector + Row-Level Security

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE hub_resources (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL,
    source text NOT NULL,
    source_site text,
    source_id text NOT NULL,
    type text NOT NULL,
    slug text,
    title text,
    body_html text,
    body_text text,
    price numeric,
    currency text,
    locale text,
    url text,
    published_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now(),
    attributes jsonb NOT NULL DEFAULT '{}',
    seo jsonb NOT NULL DEFAULT '{}',
    tags text[] NOT NULL DEFAULT '{}',
    images text[] NOT NULL DEFAULT '{}',
    embedding vector(1536)
);

CREATE UNIQUE INDEX uq_resource_origin ON hub_resources (tenant_id, source, COALESCE(source_site,''), source_id);

ALTER TABLE hub_resources ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON hub_resources
    USING (tenant_id = current_setting('app.tenant_id')::uuid);
```

Service Layer
-------------

```python
# hub/services/resource_service.py
class ResourceService:
    def __init__(self, session_factory: SessionFactory, embedder: Embedder):
        self._session_factory = session_factory
        self._embedder = embedder

    def upsert(self, *, tenant_id: UUID, resources: list[HubResource]) -> list[HubResource]:
        with self._session_factory() as session:
            rows = [HubResourceRow(**r.model_dump(exclude={"embedding"})) for r in resources]
            for row in rows:
                session.merge(row)
            session.commit()
        self.enqueue_embedding(tenant_id, [r.id for r in resources])
        return resources

    def search(self, tenant_id: UUID, q: str | None, type: str | None) -> list[HubResource]:
        with self._session_factory() as session:
            stmt = select(HubResourceRow).where(HubResourceRow.tenant_id == tenant_id)
            if type:
                stmt = stmt.where(HubResourceRow.type == type)
            if q:
                stmt = stmt.where(functools.reduce(and_, build_filters(q)))
            return [HubResource.model_validate(row.__dict__) for row in session.scalars(stmt)]

    def enqueue_embedding(self, tenant_id: UUID, ids: list[UUID]):
        embedding_tasks.enqueue(tenant_id=tenant_id, resource_ids=ids)
```

API Surface (FastAPI)
---------------------

```python
# hub/api/resources.py
r = APIRouter(prefix="/resources", tags=["resources"])

@r.get("/", response_model=list[HubResource])
def list_resources(q: str | None = None, type: str | None = None, tenant=Depends(current_tenant)):
    return resource_service.search(tenant_id=tenant.id, q=q, type=type)

@r.get("/{resource_id}", response_model=HubResource)
def get_resource(resource_id: UUID, tenant=Depends(current_tenant)):
    return resource_service.get(tenant.id, resource_id)

@r.post("/sync/{source}", status_code=202)
def trigger_sync(source: SourceType, tenant=Depends(current_tenant)):
    sync_dispatcher.enqueue(source=source, tenant_id=tenant.id)
    return {"ok": True}
```

### WordPress site integrations

Tenants can now manage GA4/GTM, consent, session replay, and feedback metadata centrally via `/wordpress/sites/{site_id}/integrations`.

```python
@r.get("/wordpress/sites/{site_id}/integrations", response_model=SiteIntegration)
def fetch_site(site_id: str, tenant=Depends(current_tenant)):
    return site_integration_service.get(tenant.id, site_id)

@r.put("/wordpress/sites/{site_id}/integrations", response_model=SiteIntegration)
def upsert_site(site_id: str, payload: SiteIntegrationUpdate, tenant=Depends(current_tenant)):
    return site_integration_service.upsert(tenant.id, site_id, payload)
```

Rows live in the `site_integrations` table (`SiteIntegrationRow` in `hub/db/models.py`) with a uniqueness constraint on `(tenant_id, site_id)` plus tenant-scoped RLS, mirroring the behaviour of `hub_resources`.

Adapter Strategy
----------------

### WordPress

```python
# hub/adapters/wordpress.py
def map_wp_post(post: dict, tenant_id: UUID, site_id: str) -> HubResource:
    meta = post.get("meta", {})
    yoast = post.get("yoast_head_json") or {}

    return HubResource(
        id=uuid.uuid7(),
        tenant_id=tenant_id,
        source="wordpress",
        source_site=site_id,
        source_id=str(post["id"]),
        type=_infer_wp_type(post["post_type"]),
        slug=post.get("slug"),
        title=post.get("title", {}).get("rendered"),
        body_html=post.get("content", {}).get("rendered"),
        body_text=_strip_html(post.get("content", {}).get("rendered")),
        tags=_resolve_terms(post.get("tags", [])),
        attributes=_flat_meta(meta),
        seo={
            "title": yoast.get("title"),
            "description": yoast.get("description"),
            "schema": yoast.get("schema"),
        },
        url=post.get("link"),
        published_at=_parse_dt(post.get("date_gmt")),
        updated_at=_parse_dt(post.get("modified_gmt")) or datetime.utcnow(),
    )
```

### Shopify

```python
# hub/adapters/shopify.py
def map_shopify_product(product: dict, tenant_id: UUID, store_domain: str) -> HubResource:
    primary_variant = (product.get("variants") or [{}])[0]
    metafields = product.get("metafields") or []

    return HubResource(
        id=uuid.uuid7(),
        tenant_id=tenant_id,
        source="shopify",
        source_site=store_domain,
        source_id=product["id"],
        type="product",
        slug=product.get("handle"),
        title=product.get("title"),
        body_html=product.get("bodyHtml"),
        body_text=_strip_html(product.get("bodyHtml")),
        price=_extract_price(primary_variant),
        currency=_extract_currency(primary_variant),
        images=[i.get("url") for i in product.get("images", [])],
        tags=product.get("tags", []),
        attributes=_map_metafields(metafields),
        seo=_derive_seo(product.get("seo")),
        url=f"https://{store_domain}/products/{product.get('handle')}",
        published_at=_parse_dt(product.get("publishedAt")),
        updated_at=_parse_dt(product.get("updatedAt")) or datetime.utcnow(),
    )
```

Sync Workers
------------

1. **WordPress pull**
   - Iterate WP REST (`/wp/v2/{post_type}` with pagination).
   - Map via adapter, upsert chunked batches (100).
   - Capture meta/ACF via `_fields=meta,acf` or secondary fetch.

2. **Shopify pull**
   - Use bulk GraphQL (products + metafields) per tenant store.
   - Map, upsert, requeue update for embeddings.

3. **Embedding enrichment**
   - Background job fetches `body_text + seo` → sends to embedding model → writes into `embedding` column.

4. **Push-backs (optional)**
   - Accept automation diffs, call adapter `to_wordpress` / `to_shopify` to build REST/GraphQL payloads.
   - Stage updates for approval; only commit after user review.

Search & Automations
--------------------
- Combine pgvector similarity search (semantic) with SQL filters (type, locale, tags).
- Automations (SEO, bulk edits) operate on `HubResource` objects, making the same logic reusable across platforms.
- When automations mutate a resource, write back to `hub_resources` and trigger a push-back task for the source platform.

Read-only Portal Views
----------------------
- Minimal FastAPI + React portal lists resources with filters.
- Detail view renders normalized fields + raw attributes JSON for debugging.
- Include “Sync now” CTA per source to enqueue Celery jobs.

Future Enhancements
-------------------
1. **Variant table:** `hub_variants` keyed by `(resource_id, variant_id)` for Shopify variant data.
2. **Change audit:** `hub_resource_events` to track inbound/outbound edits for compliance.
3. **Preview sandbox:** For push-backs, store rendered HTML diff snapshots before publishing.
4. **Adapter SDK:** Provide helpers so new platforms only implement `pull()`, `map()`, and `push()`.
5. **Horizontal sharding:** Once tenant counts warrant it, split `hub_resources` by geography and replicate embeddings via vector-aware CDC.

Runtime Bootstrap
-----------------
- `hub/docker-compose.yml` offers a one-command Postgres (pgvector) + Redis stack for local testing.
- Copy `hub/.env.example` to `.env` and run `python -m hub.scripts.bootstrap_db` to load the initial schema.
- Start FastAPI via `uvicorn hub.main:app --reload` and workers via `celery -A hub.tasks worker -l info` in separate shells.
- Configure OpenAI embeddings by setting `OPENAI_API_KEY`; otherwise the deterministic provider keeps pipelines functional without network access.

Implementation Checklist (First Slice)
-------------------------------------
1. Create `hub_resources` table + pgvector + RLS.
2. Land `HubResource` Pydantic + SQLAlchemy models.
3. Build `ResourceService` (upsert, get, search) + embedding enqueue hook.
4. Ship FastAPI `/resources` router + tenant dependency.
5. WordPress + Shopify adapters (pull-only) + Celery sync tasks.
6. Basic portal view (list + detail) to validate normalized payloads.

WordPress Plugin Alignment
--------------------------
- The existing plugin should push automation results to the Hub using `/resources/{id}` updates rather than bespoke endpoints per content type.
- Plugin sync webhooks can be simplified: instead of streaming full post payloads, send the `source_id` + tenant token so the Hub backend fetches and rehydrates via the adapter.
- When the plugin requests SEO suggestions, it should only supply the normalized `HubResource` ID; the backend chooses the correct platform adapter automatically.

Open Questions
--------------
- **Drive adapter scope:** Do we normalize Docs + Sheets in one schema or split by subtype?
- **Large body_html fields:** For very large Shopify descriptions, should we store HTML in object storage and keep a pointer in `HubResource`?
- **Attribute typing:** Current proposal stringifies everything. Do we need typed JSON (numbers/bools) for advanced automations?
