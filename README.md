# AI Hub SEO Sync Plugin

This repository packages the WordPress plugin that connects tenant sites to the AI Hub Phase&nbsp;2 backend.
The plugin fetches AI-generated SEO drafts from AI Hub, applies them to WordPress posts, and acknowledges the
result so the Hub can track progress per tenant.

## Features

- Securely stores Hub connection details (base URL, site ID, automation token) in WordPress options.
- Schedules background syncs via WP-Cron and exposes a REST endpoint for manual triggers.
- Provides an admin settings screen where authorized users can rotate tokens, run immediate syncs, and review status.
- Fetches the AI Hub dashboard manifest and exposes a tenant-aware dashboard browser inside WordPress, surfacing each dashboard as its own AIMXB submenu.
- Bundles a React/DataViews-ready admin widget compiled with Vite for richer UX in future iterations.
- Injects GA4/GTM, self-hosted session replay, and an open-source feedback widget per tenant with consent-aware gating.

## Requirements

- WordPress 6.2 or newer.
- PHP 8.1 or newer.
- AI Hub backend with the `/admin/wordpress/sites`, `/wordpress/seo/pull`, and `/wordpress/seo/apply` endpoints enabled.
- A tenant automation token issued from the AI Hub admin portal.

## Universal Connector Backend (FastAPI)

This repository now ships a reference backend (`hub/`) that normalizes resources from WordPress, Shopify, Google Drive, etc., into a shared `HubResource` schema so automations run independent of the origin platform.

### Prerequisites

- Python 3.11+
- Postgres 15+ with the `pgvector` extension
- Redis 6+ (Celery broker + result backend)
- Environment variables:
  - `DATABASE_URL=postgresql+psycopg://user:pass@localhost:5432/aihub`
  - `REDIS_URL=redis://localhost:6379/0`
  - `OPENAI_API_KEY` *(optional if you swap in a real embedding provider)*

### Install & run

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r hub/requirements.txt

# Copy env template and start infra
cp hub/.env.example .env
docker compose -f hub/docker-compose.yml up -d

# Bootstrap the database schema
python -m hub.scripts.bootstrap_db

# Start the API
uvicorn hub.main:app --reload

# Start Celery workers (separate shell)
celery -A hub.tasks worker -l info
```

The API exposes:

- `GET /resources` — filterable list of normalized resources.
- `GET /resources/{id}` — fetch a single resource.
- `POST /resources/sync/{source}` — enqueue a sync job for `wordpress` or `shopify`. Provide the tenant headers (`X-Tenant-ID`, optional `X-Source-Site`) and a JSON body with connection details:

```bash
curl -X POST http://localhost:8000/resources/sync/shopify \
  -H "Content-Type: application/json" \
  -H "X-Tenant-ID: 11111111-2222-3333-4444-555555555555" \
  -d '{"store_domain":"example.myshopify.com","access_token":"shpat_***"}'
```

Sync workers live in `hub/tasks/` and leverage the adapters in `hub/adapters/` to map platform-specific payloads to the canonical schema defined in `hub/models/resource.py`. See `docs/universal-connector.md` for the full architecture blueprint.

### Tenant instrumentation storage

The backend now persists per-site analytics settings so marketing ops can maintain a single source of truth for GA4/GTM IDs, consent cookies, session replay, and feedback widgets. Use the `/wordpress/sites/{site_id}/integrations` endpoints with the usual `X-Tenant-ID` header to read or update a tenant’s configuration.

```bash
curl -X PUT http://localhost:8000/wordpress/sites/site-123/integrations \
  -H "Content-Type: application/json" \
  -H "X-Tenant-ID: 11111111-2222-3333-4444-555555555555" \
  -d '{
        "ga_measurement_id": "G-ABC1234567",
        "gtm_container_id": "GTM-XYZ987",
        "conversion_event": "generate_lead",
        "consent_cookie_name": "hub_consent",
        "session_replay_enabled": true,
        "session_replay_project_key": "openreplay-project",
        "session_replay_host": "https://replay.example.com",
        "session_replay_mask_selectors": ["input[type=password]", ".ssn"],
        "feedback_enabled": true,
        "feedback_widget_url": "https://feedback.example.com/widget.js",
        "feedback_project_key": "mortgages"
      }'

curl -H "X-Tenant-ID: 11111111-2222-3333-4444-555555555555" \
  http://localhost:8000/wordpress/sites/site-123/integrations
```

Rows live in the new `site_integrations` table (scoped by tenant via RLS) so WordPress settings, Hub dashboards, and internal automations all reference the same instrumentation metadata.

### Embeddings

- Set `OPENAI_API_KEY` and optionally override `EMBEDDING_MODEL` in `.env` to stream embeddings through OpenAI. If the key is missing or invalid, the service falls back to a deterministic hash-based embedding so pipelines remain testable offline.
- Embedding jobs run asynchronously via `hub.tasks.embeddings`. They populate the pgvector column for semantic search once a sync finishes.

## Getting Started

1. **Install dependencies**

   ```bash
   composer install
   npm install
   npm run build
   ```

   The build step compiles the admin React bundle into `dist/` and produces a Vite manifest consumed by the plugin loader.

2. **Activate the plugin**  
   Upload the plugin to the tenant's WordPress installation (or symlink it in `wp-content/plugins`) and activate it from the
   WordPress admin dashboard.

3. **Configure connection details**
   - Navigate to **AIMXB → Settings** (the plugin menu previously labeled “AI Hub”).
   - Enter the Hub base URL, tenant site ID, automation token, and desired sync cadence (minimum 5 minutes).
   - Save the settings. The plugin stores them in the `ai_hub_wp_settings` option with `autoload = false`.

4. **Wire analytics, session replay, and feedback**  
   - In the **Analytics & Tag Manager** section choose either a GA4 Measurement ID (`G-XXXX...`) or a GTM container (`GTM-XXXX...`). Override the consent-cookie name/value if your CMP writes something other than `hub_consent=deny`, and pick the default conversion event (`generate_lead` is the default).
   - Toggle **Session Replay** on to embed OpenReplay (or another compatible self-hosted recorder). Supply the project key, ingest host, and any comma-separated CSS selectors to mask.
   - Toggle **Feedback Widget** on to load your self-hosted widget (Astuto/Feedbase, etc.) by pasting its script URL and project key.
   - These integrations are optional; leave the fields blank to skip them for a tenant.

5. **Run the first sync**  
   Use the **Run Sync Now** button or wait for the scheduled cron event. Successful runs update the “Last Sync” timestamp and
   emit acknowledgements back to AI Hub.

## Manual Sync Endpoint

The plugin registers `POST /wp-json/ai-hub/v1/sync`. Authenticated administrators (with a valid REST nonce) can call this endpoint
to trigger a sync from external tooling or from the React admin dashboard.

```http
POST /wp-json/ai-hub/v1/sync
Headers: X-WP-Nonce: <rest nonce>
```

## Dashboard Endpoints

Two additional REST routes proxy the new AI Hub dashboard APIs:

- `GET /wp-json/ai-hub/v1/dashboards` &mdash; returns the filtered dashboard manifest for the configured tenant.
- `GET /wp-json/ai-hub/v1/dashboards/{slug}` &mdash; returns detailed metadata for a specific dashboard.

These endpoints require administrator capabilities and the standard REST nonce. The bundled React admin experience uses them to
render a tenant-aware dashboard index and placeholder detail view.

## Development Notes

- **Cron Intervals**: Custom intervals (`five_minutes`, `fifteen_minutes`, `thirty_minutes`) are injected via `cron_schedules`.
- **Sync Logic**: `AIHub\WordPress\Sync\SyncService` orchestrates fetching drafts, mapping them to posts, and sending acknowledgements.
  Adjust the `upsertPost` method if your site requires bespoke mapping logic (e.g., custom post types or SEO plugins).
- **Error Handling**: Failures are recorded in the settings option and surfaced in the admin UI. WordPress’ debug log also captures
  errors emitted during cron runs.
- **JS Bundle**: The admin widget is optional. If `dist/manifest.json` is absent the plugin silently skips enqueuing scripts,
  enabling headless/API-only installs.
- **Front-end instrumentation**: When analytics is enabled the plugin prints the GA4 or GTM snippet in `wp_head`, loads OpenReplay (if configured), and injects the feedback widget in `wp_footer`. It also defines a lightweight bridge so any form or block can trigger conversions without touching gtag directly:

  ```js
  const params = { value: 1, currency: 'USD' };
  window.dispatchEvent(new CustomEvent('ai-hub:conversion', {
    detail: { event: 'generate_lead', params }
  }));
  ```

  Two events are supported out of the box:

  - `ai-hub:conversion` — forwards the provided event (or the default from settings) to `gtag()` / `dataLayer`.
  - `ai-hub:lead-submitted` — uses the default conversion event so CRM webhooks and GA4 stay in lockstep.

  Developers can also call `window.AIHubTracking.trackConversion('custom_event', { value: 99 })` manually.
- **Consent hooks**: Override the consent check with filters such as `ai_hub_allow_tracking`, `ai_hub_allow_analytics`, `ai_hub_allow_session_replay`, or `ai_hub_allow_feedback`. Returning `true`/`false` from
  any of these filters short-circuits the default cookie-based logic. The session replay script URL can be replaced with
  `ai_hub_session_replay_script`.

## Testing

```bash
composer run lint
composer run test
npm run lint
npm run typecheck
npm run build
```

Integrate these commands into CI to guarantee the plugin remains compatible with the enforced WordPress and PHP coding standards.

### Spin up a WordPress sandbox with `wp-env`

Use the official WordPress Docker tooling when you want a disposable site without maintaining your own Compose file:

1. Install the helper once: `npm install --global @wordpress/env`
2. Start the environment: `wp-env start`
3. Log in at `http://localhost:8888/wp-admin` (default `admin` / `password`) and activate **AI Hub SEO Sync**.
4. Run tasks inside the containers when needed:
   - `wp-env run cli wp cron event run ai_hub_wp_sync_event`
   - `wp-env run tests-cli phpunit`
5. Stop with `wp-env stop` or reset using `wp-env destroy`.

The committed `wp-env.json` pins WordPress 6.5 on PHP 8.1, enables `WP_DEBUG`, and mounts this plugin directory automatically.

### Alternative: manual Docker Compose stack

If you prefer managing services directly, use the bundled `docker-compose.yml` to run WordPress and MySQL:

```bash
docker compose up -d
```

Complete the installation at `http://localhost:8080`, activate the plugin, and configure the AI Hub connection. While the stack is live you can run helpful commands such as:

```bash
docker compose exec wordpress wp cron event run ai_hub_wp_sync_event
docker compose exec wordpress wp option get ai_hub_wp_settings
docker compose exec wordpress curl -s http://localhost/wp-json/ai-hub/v1/sync
```

Shut everything down with `docker compose down`.

## Next Steps

- Expand the React admin experience using WordPress DataViews for richer reporting.
- Add integration tests (e.g., Playwright) to verify end-to-end sync behaviour against a staging Hub instance.
- Hook into AI Hub’s template sync endpoints once the template library is published.
