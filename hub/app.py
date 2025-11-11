"""FastAPI application factory."""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from .api.resources import r as resources_router
from .api.sites import r as sites_router
from .config import get_settings
from .db.base import Base
from .db.session import SessionFactory, engine, init_extensions
from .services.embedder import EmbeddingQueue
from .services.resource_service import ResourceService
from .services.site_integration_service import SiteIntegrationService


def create_app() -> FastAPI:
    settings = get_settings()
    init_extensions()
    Base.metadata.create_all(bind=engine)

    app = FastAPI(title=settings.app_name)
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_allow_origins,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

    app.state.resource_service = ResourceService(session_factory=SessionFactory, embed_queue=EmbeddingQueue())
    app.state.site_integration_service = SiteIntegrationService(session_factory=SessionFactory)
    app.include_router(resources_router)
    app.include_router(sites_router)
    return app
