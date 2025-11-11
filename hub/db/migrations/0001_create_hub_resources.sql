CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS hub_resources (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL,
    source text NOT NULL,
    source_site text,
    source_id text NOT NULL,
    type text NOT NULL,
    slug text,
    title text,
    body_html text,
    body_text text,
    price numeric,
    currency text,
    locale text,
    url text,
    published_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now(),
    attributes jsonb NOT NULL DEFAULT '{}',
    seo jsonb NOT NULL DEFAULT '{}',
    tags text[] NOT NULL DEFAULT '{}',
    images text[] NOT NULL DEFAULT '{}',
    embedding vector(1536)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_resource_origin
    ON hub_resources (tenant_id, source, COALESCE(source_site, ''), source_id);

ALTER TABLE hub_resources ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation ON hub_resources;
CREATE POLICY tenant_isolation ON hub_resources
    USING (tenant_id = current_setting('app.tenant_id')::uuid);
