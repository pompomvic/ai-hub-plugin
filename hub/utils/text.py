"""Utility helpers for adapters."""

from __future__ import annotations

import re
from bs4 import BeautifulSoup


def strip_html(value: str | None) -> str | None:
    if not value:
        return None
    soup = BeautifulSoup(value, "html.parser")
    text = soup.get_text(" ", strip=True)
    return re.sub(r"\s+", " ", text).strip() if text else None
