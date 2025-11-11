"""Configuration helpers for the hub backend."""

from functools import lru_cache
from typing import ClassVar

from pydantic import AnyUrl, BaseModel, Field


class Settings(BaseModel):
    """Runtime configuration sourced from env vars or `.env`."""

    app_name: str = Field(default="ai-hub-universal-connector")
    database_url: AnyUrl = Field(alias="DATABASE_URL")
    redis_url: AnyUrl = Field(alias="REDIS_URL")
    openai_api_key: str | None = Field(default=None, alias="OPENAI_API_KEY")
    embedding_model: str = Field(default="text-embedding-3-small")
    trust_proxy_headers: bool = Field(default=True)
    cors_allow_origins: list[str] = Field(default_factory=lambda: ["*"])

    model_config: ClassVar = {
        "env_file": ".env",
        "populate_by_name": True,
    }


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()  # type: ignore[arg-type]
