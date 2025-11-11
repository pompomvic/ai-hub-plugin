"""Business logic for hub resource persistence and queries."""

from __future__ import annotations

from uuid import UUID, uuid7
from typing import Callable, Iterable, Sequence

from sqlalchemy import Select, func, or_, select, tuple_
from sqlalchemy.exc import NoResultFound
from sqlalchemy.orm import Session

from ..db.models import HubResourceRow
from ..db.session import apply_tenant_rls
from ..models.resource import HubResource
from .embedder import EmbeddingQueue


class ResourceNotFoundError(RuntimeError):
    pass


class ResourceService:
    def __init__(self, session_factory: Callable[[], Session], embed_queue: EmbeddingQueue):
        self._session_factory = session_factory
        self._embed_queue = embed_queue

    def upsert(self, *, tenant_id: UUID, resources: Sequence[HubResource]) -> list[HubResource]:
        session = self._session_factory()
        try:
            apply_tenant_rls(session, str(tenant_id))
            self._ensure_ids(session, tenant_id, resources)
            rows = [self._to_row(resource) for resource in resources]
            for row in rows:
                session.merge(row)
            session.commit()
        finally:
            session.close()

        self._embed_queue.enqueue(tenant_id, (resource.id for resource in resources))
        return list(resources)

    def get(self, tenant_id: UUID, resource_id: UUID) -> HubResource:
        session = self._session_factory()
        try:
            apply_tenant_rls(session, str(tenant_id))
            stmt: Select[HubResourceRow] = select(HubResourceRow).where(HubResourceRow.id == resource_id)
            row = session.execute(stmt).scalar_one()
            return self._to_model(row)
        except NoResultFound as exc:  # pragma: no cover - defensive guard
            raise ResourceNotFoundError(str(resource_id)) from exc
        finally:
            session.close()

    def search(self, tenant_id: UUID, *, q: str | None = None, type_: str | None = None) -> list[HubResource]:
        session = self._session_factory()
        try:
            apply_tenant_rls(session, str(tenant_id))
            stmt: Select[HubResourceRow] = select(HubResourceRow).where(HubResourceRow.tenant_id == tenant_id)
            if type_:
                stmt = stmt.where(HubResourceRow.type == type_)
            if q:
                pattern = f"%{q}%"
                stmt = stmt.where(
                    or_(
                        HubResourceRow.title.ilike(pattern),
                        HubResourceRow.slug.ilike(pattern),
                        HubResourceRow.body_text.ilike(pattern),
                        func.array_to_string(HubResourceRow.tags, ",").ilike(pattern),
                    )
                )

            rows = session.execute(stmt.order_by(HubResourceRow.updated_at.desc())).scalars().all()
            return [self._to_model(row) for row in rows]
        finally:
            session.close()

    def _to_row(self, resource: HubResource) -> HubResourceRow:
        data = resource.model_dump(exclude={"embedding"})
        return HubResourceRow(**data)

    def _to_model(self, row: HubResourceRow) -> HubResource:
        payload = {column.name: getattr(row, column.name) for column in row.__table__.columns}
        return HubResource.model_validate(payload)

    def _ensure_ids(self, session: Session, tenant_id: UUID, resources: Sequence[HubResource]) -> None:
        missing = {
            (resource.source, (resource.source_site or ""), resource.source_id): idx
            for idx, resource in enumerate(resources)
            if resource.source_id and resource.id is None
        }
        if not missing:
            for resource in resources:
                if resource.id is None:
                    resource.id = uuid7()
            return

        stmt = select(
            HubResourceRow.id,
            HubResourceRow.source,
            func.coalesce(HubResourceRow.source_site, "").label("source_site"),
            HubResourceRow.source_id,
        ).where(
            HubResourceRow.tenant_id == tenant_id,
            tuple_(HubResourceRow.source, func.coalesce(HubResourceRow.source_site, ""), HubResourceRow.source_id).in_(
                [(k[0], k[1], k[2]) for k in missing.keys()]
            ),
        )

        rows = session.execute(stmt).all()
        existing = {
            (row.source, row.source_site, row.source_id): row.id for row in rows
        }

        for resource in resources:
            if resource.id is not None:
                continue
            key = (resource.source, resource.source_site or "", resource.source_id)
            resource.id = existing.get(key, uuid7())
