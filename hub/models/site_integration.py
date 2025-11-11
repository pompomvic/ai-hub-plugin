"""Pydantic models for per-site instrumentation settings."""

from __future__ import annotations

from datetime import datetime
from typing import List, Optional

from pydantic import BaseModel, Field


class SiteIntegration(BaseModel):
    site_id: str
    ga_measurement_id: Optional[str] = None
    gtm_container_id: Optional[str] = None
    conversion_event: Optional[str] = None
    consent_cookie_name: Optional[str] = None
    consent_opt_out_value: Optional[str] = None
    session_replay_enabled: bool = False
    session_replay_project_key: Optional[str] = None
    session_replay_host: Optional[str] = None
    session_replay_mask_selectors: List[str] = Field(default_factory=list)
    feedback_enabled: bool = False
    feedback_widget_url: Optional[str] = None
    feedback_project_key: Optional[str] = None
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None


class SiteIntegrationUpdate(BaseModel):
    ga_measurement_id: Optional[str] = None
    gtm_container_id: Optional[str] = None
    conversion_event: Optional[str] = None
    consent_cookie_name: Optional[str] = None
    consent_opt_out_value: Optional[str] = None
    session_replay_enabled: Optional[bool] = None
    session_replay_project_key: Optional[str] = None
    session_replay_host: Optional[str] = None
    session_replay_mask_selectors: Optional[List[str]] = None
    feedback_enabled: Optional[bool] = None
    feedback_widget_url: Optional[str] = None
    feedback_project_key: Optional[str] = None
