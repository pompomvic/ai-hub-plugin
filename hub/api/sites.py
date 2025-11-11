"""WordPress site integration endpoints."""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, status

from ..models.site_integration import SiteIntegration, SiteIntegrationUpdate
from ..services.site_integration_service import (
    SiteIntegrationNotFound,
    SiteIntegrationService,
)
from .deps import current_tenant, site_integration_service

r = APIRouter(prefix="/wordpress/sites", tags=["wordpress"])


@r.get("/{site_id}/integrations", response_model=SiteIntegration)
def fetch_site_integration(
    site_id: str,
    tenant=Depends(current_tenant),
    service: SiteIntegrationService = Depends(site_integration_service),
):
    try:
        return service.get(tenant.id, site_id)
    except SiteIntegrationNotFound as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc


@r.put("/{site_id}/integrations", response_model=SiteIntegration)
def upsert_site_integration(
    site_id: str,
    payload: SiteIntegrationUpdate,
    tenant=Depends(current_tenant),
    service: SiteIntegrationService = Depends(site_integration_service),
):
    return service.upsert(tenant.id, site_id, payload)

