"""Resource endpoints."""

from __future__ import annotations

from uuid import UUID

from fastapi import APIRouter, Body, Depends, HTTPException, status
from pydantic import BaseModel

from ..models.resource import HubResource, SourceType
from ..services.resource_service import ResourceNotFoundError, ResourceService
from ..tasks.dispatcher import SyncDispatchError, dispatch_sync
from .deps import current_tenant, resource_service

r = APIRouter(prefix="/resources", tags=["resources"])


@r.get("/", response_model=list[HubResource])
def list_resources(
    q: str | None = None,
    type: str | None = None,  # noqa: A002 - query param name
    tenant=Depends(current_tenant),
    service: ResourceService = Depends(resource_service),
):
    return service.search(tenant.id, q=q, type_=type)


@r.get("/{resource_id}", response_model=HubResource)
def get_resource(
    resource_id: UUID,
    tenant=Depends(current_tenant),
    service: ResourceService = Depends(resource_service),
):
    try:
        return service.get(tenant.id, resource_id)
    except ResourceNotFoundError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc


class SyncRequest(BaseModel):
    store_domain: str | None = None
    access_token: str | None = None
    base_url: str | None = None
    site_id: str | None = None
    auth_token: str | None = None
    post_types: list[str] | None = None
    api_version: str | None = None


@r.post("/sync/{source}", status_code=status.HTTP_202_ACCEPTED)
def trigger_sync(
    source: SourceType,
    payload: SyncRequest = Body(...),
    tenant=Depends(current_tenant),
):
    try:
        dispatch_sync(source, tenant_id=tenant.id, payload=payload.model_dump(exclude_none=True))
    except SyncDispatchError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    return {"ok": True}
