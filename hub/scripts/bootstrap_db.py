"""Apply SQL migrations for the hub backend."""

from __future__ import annotations

import pathlib

import psycopg
from dotenv import load_dotenv

from hub.config import get_settings


def main() -> None:
    load_dotenv()
    settings = get_settings()
    sql_path = pathlib.Path(__file__).resolve().parents[1] / "db" / "migrations" / "0001_create_hub_resources.sql"
    sql = sql_path.read_text()
    dsn = settings.database_url.unicode_string()
    with psycopg.connect(dsn) as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
        conn.commit()
    print("Applied migrations from", sql_path)


if __name__ == "__main__":
    main()
