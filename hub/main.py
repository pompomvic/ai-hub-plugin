"""ASGI entrypoint."""

from __future__ import annotations

import uvicorn

from .app import create_app


app = create_app()


if __name__ == "__main__":
    uvicorn.run("hub.main:app", reload=True)
