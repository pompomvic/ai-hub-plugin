"""FastAPI dependency helpers."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Annotated
from uuid import UUID

from fastapi import Depends, Header, HTTPException, Request, status


@dataclass(slots=True)
class Tenant:
    id: UUID
    source_site: str | None = None


TenantHeader = Annotated[str, Header(alias="X-Tenant-ID")]


def current_tenant(tenant_id: TenantHeader, request: Request, source_site: str | None = Header(default=None, alias="X-Source-Site")) -> Tenant:
    try:
        tenant_uuid = UUID(tenant_id)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid tenant id") from exc
    request.state.tenant_id = tenant_uuid
    return Tenant(id=tenant_uuid, source_site=source_site)


def resource_service(request: Request):
    service = getattr(request.app.state, "resource_service", None)
    if service is None:
        raise HTTPException(status_code=500, detail="Resource service not configured")
    return service


def site_integration_service(request: Request):
    service = getattr(request.app.state, "site_integration_service", None)
    if service is None:
        raise HTTPException(status_code=500, detail="Site integration service not configured")
    return service
