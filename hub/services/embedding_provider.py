"""Embedding provider abstraction."""

from __future__ import annotations

import hashlib
from typing import Protocol

from openai import OpenAI, OpenAIError

from ..config import get_settings


class EmbeddingProvider(Protocol):
    def embed(self, text: str) -> list[float]:
        ...


class DeterministicEmbeddingProvider:
    def __init__(self, dim: int = 1536):
        self.dim = dim

    def embed(self, text: str) -> list[float]:
        digest = hashlib.sha256(text.encode("utf-8")).digest()
        values = list(digest)
        if len(values) < self.dim:
            repeat = (self.dim // len(values)) + 1
            values = (values * repeat)[: self.dim]
        return [val / 255 for val in values[: self.dim]]


class OpenAIEmbeddingProvider:
    def __init__(self, api_key: str, model: str):
        self._client = OpenAI(api_key=api_key)
        self._model = model
        self._fallback = DeterministicEmbeddingProvider()

    def embed(self, text: str) -> list[float]:
        try:
            response = self._client.embeddings.create(model=self._model, input=text)
            return response.data[0].embedding
        except OpenAIError:
            return self._fallback.embed(text)


def get_embedding_provider() -> EmbeddingProvider:
    settings = get_settings()
    if settings.openai_api_key:
        try:
            return OpenAIEmbeddingProvider(settings.openai_api_key, settings.embedding_model)
        except OpenAIError:  # pragma: no cover - fallback if credentials invalid
            pass
    return DeterministicEmbeddingProvider()
