"""Session helpers for connecting to Postgres."""

from __future__ import annotations

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, sessionmaker

from ..config import get_settings


settings = get_settings()

engine = create_engine(str(settings.database_url), future=True, pool_pre_ping=True)
SessionFactory = sessionmaker(bind=engine, expire_on_commit=False, class_=Session)


def init_extensions() -> None:
    """Ensure pgvector exists before migrations run."""

    with engine.connect() as conn:
        conn.execute(text("CREATE EXTENSION IF NOT EXISTS vector"))
        conn.commit()


def get_session() -> Session:
    return SessionFactory()


def apply_tenant_rls(session: Session, tenant_id: str) -> None:
    """Set session-local tenant for row-level security policies."""

    session.execute(text("SELECT set_config('app.tenant_id', :tenant_id, true)"), {"tenant_id": tenant_id})
