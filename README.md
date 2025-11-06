# AI Hub SEO Sync Plugin

This repository packages the WordPress plugin that connects tenant sites to the AI Hub Phase&nbsp;2 backend.
The plugin fetches AI-generated SEO drafts from AI Hub, applies them to WordPress posts, and acknowledges the
result so the Hub can track progress per tenant.

## Features

- Securely stores Hub connection details (base URL, site ID, automation token) in WordPress options.
- Schedules background syncs via WP-Cron and exposes a REST endpoint for manual triggers.
- Provides an admin settings screen where authorized users can rotate tokens, run immediate syncs, and review status.
- Fetches the AI Hub dashboard manifest and exposes a tenant-aware dashboard browser inside WordPress.
- Bundles a React/DataViews-ready admin widget compiled with Vite for richer UX in future iterations.

## Requirements

- WordPress 6.2 or newer.
- PHP 8.1 or newer.
- AI Hub backend with the `/admin/wordpress/sites`, `/wordpress/seo/pull`, and `/wordpress/seo/apply` endpoints enabled.
- A tenant automation token issued from the AI Hub admin portal.

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
   - Navigate to **AI Hub → Settings**.
   - Enter the Hub base URL, tenant site ID, automation token, and desired sync cadence (minimum 5 minutes).
   - Save the settings. The plugin stores them in the `ai_hub_wp_settings` option with `autoload = false`.

4. **Run the first sync**  
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
