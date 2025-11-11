"""Business logic for storing per-site instrumentation settings."""

from __future__ import annotations

from typing import Callable
from uuid import UUID, uuid4

from sqlalchemy.exc import NoResultFound
from sqlalchemy.orm import Session

from ..db.models import SiteIntegrationRow
from ..db.session import apply_tenant_rls
from ..models.site_integration import SiteIntegration, SiteIntegrationUpdate


class SiteIntegrationNotFound(RuntimeError):
    """Raised when a site has not been configured."""

    def __init__(self, site_id: str):
        super().__init__(f"Site '{site_id}' is not configured.")


class SiteIntegrationService:
    def __init__(self, session_factory: Callable[[], Session]):
        self._session_factory = session_factory

    def get(self, tenant_id: UUID, site_id: str) -> SiteIntegration:
        session = self._session_factory()
        try:
            apply_tenant_rls(session, str(tenant_id))
            row = (
                session.query(SiteIntegrationRow)
                .where(
                    SiteIntegrationRow.tenant_id == tenant_id,
                    SiteIntegrationRow.site_id == site_id,
                )
                .one()
            )
            return self._to_model(row)
        except NoResultFound as exc:
            raise SiteIntegrationNotFound(site_id) from exc
        finally:
            session.close()

    def upsert(self, tenant_id: UUID, site_id: str, payload: SiteIntegrationUpdate) -> SiteIntegration:
        session = self._session_factory()
        try:
            apply_tenant_rls(session, str(tenant_id))
            try:
                row = (
                    session.query(SiteIntegrationRow)
                    .where(
                        SiteIntegrationRow.tenant_id == tenant_id,
                        SiteIntegrationRow.site_id == site_id,
                    )
                    .with_for_update()
                    .one()
                )
            except NoResultFound:
                row = SiteIntegrationRow(id=uuid4(), tenant_id=tenant_id, site_id=site_id)
                session.add(row)

            self._apply_payload(row, payload)
            session.commit()
            session.refresh(row)
            return self._to_model(row)
        finally:
            session.close()

    def _apply_payload(self, row: SiteIntegrationRow, payload: SiteIntegrationUpdate) -> None:
        data = payload.model_dump(exclude_none=True)
        if not data:
            return

        for key, value in data.items():
            setattr(row, key, value)

    def _to_model(self, row: SiteIntegrationRow) -> SiteIntegration:
        return SiteIntegration(
            site_id=row.site_id,
            ga_measurement_id=row.ga_measurement_id,
            gtm_container_id=row.gtm_container_id,
            conversion_event=row.conversion_event,
            consent_cookie_name=row.consent_cookie_name,
            consent_opt_out_value=row.consent_opt_out_value,
            session_replay_enabled=row.session_replay_enabled,
            session_replay_project_key=row.session_replay_project_key,
            session_replay_host=row.session_replay_host,
            session_replay_mask_selectors=row.session_replay_mask_selectors or [],
            feedback_enabled=row.feedback_enabled,
            feedback_widget_url=row.feedback_widget_url,
            feedback_project_key=row.feedback_project_key,
            created_at=row.created_at,
            updated_at=row.updated_at,
        )
