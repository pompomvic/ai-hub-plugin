AI Hub Plugin Agent Instructions
================================

This playbook governs all automated or scripted work executed inside `~/Documents/GITHUB/ai-hub-plugin`. Follow it alongside the global Codex guidance. If a subdirectory ships its own `AGENTS.md`, treat that as an addendum, otherwise these rules apply recursively.

0. Non-Negotiables
------------------
* **Start clean.** Run `git status` and attempt `git pull --ff-only` before changing files. If the pull fails (e.g., repo lacks a remote), note it in your session log and continue only after confirming you're on the latest local commit.
* **No secrets in git.** Never commit automation tokens, tenant identifiers, or Hub credentials. Sample values belong in `.env.example`, fixtures, or redacted docs.
* **Respect WordPress guardrails.** Every PHP entry point must exit when `ABSPATH` is undefined. Keep compatibility with PHP 8.1+ and WordPress 6.2+ until the README bumps versions.
* **Coordinate with the Hub backend.** Any behaviour that depends on AI Hub endpoints, schemas, or automation flows must stay in sync with the backend repository (`hub/app/services/wordpress_sync_service.py`, `/wordpress/*` API routes). Ship cross-repo changes together or document required backend versions.
* **Artifact hygiene.** Do not commit `vendor/`, `node_modules/`, `dist/`, build caches, or OS cruft. Generated bundles should be rebuilt in CI and published via release artifacts.

1. Repository Layout
--------------------
```
ai-hub-plugin/
├── ai-hub-seo.php          # Main plugin file (defines constants, boots Plugin class).
├── src/                    # PHP source (namespaced under AIHub\\WordPress\\...).
│   ├── Admin/              # WP-Admin settings page & templates.
│   ├── Cron/               # Scheduler + cron helpers.
│   ├── Http/               # Guzzle-backed API clients.
│   ├── Rest/               # REST controllers for wp-json endpoints.
│   └── Sync/               # Sync orchestration and WordPress data mutations.
├── js/                     # React/TypeScript admin app (built with Vite).
├── assets/                 # Static assets + translation template.
├── dist/                   # Vite build output (omit from commits; regenerate locally).
├── tests/                  # PHPUnit suite (`tests/bootstrap.php` handles autoloading).
├── composer.json / lock    # PHP dependencies & scripts (lint/test).
├── package.json            # Node toolchain (`npm run build|lint|typecheck`).
├── phpcs.xml.dist          # WordPress Coding Standards ruleset.
├── phpunit.xml.dist        # Test configuration.
└── AGENTS.md               # This document.
```

2. Development Workflow
-----------------------
* **Environment setup:** Run `composer install` and `npm install` before coding. Use the `@ai-hub/wp-seo-plugin` Vite bundle for admin UI changes.
* **Autoloading:** Classes reside under `AIHub\\WordPress\\`. Register new namespaces via `composer.json` PSR-4 or `tests/bootstrap.php`.
* **Sync pipeline:** Extend behaviour in `src/Sync/SyncService.php`. Keep `run()` side-effect free beyond orchestrating fetch/apply/acknowledge; use helper methods for post mutations.
* **HTTP integrations:** Route outbound Hub calls through `src/Http/ApiClient.php`. Prefer `GuzzleHttp\\ClientInterface` injection to keep tests mockable. Never hit Hub endpoints directly inside controllers.
* **Admin UI:** Build React features under `js/admin/`, access REST data via `wp.apiFetch` or fetch wrappers, and export bundles with `npm run build`. Keep entrypoints mirrored in `vite.config.ts` and `dist/manifest.json`.
* **Settings & storage:** Persist configuration via `AIHub\\WordPress\\Settings`. Sanitize inputs with `esc_url_raw`, `sanitize_text_field`, etc., and ensure secrets (`automation_token`) are never re-displayed.

3. Security & Compliance
------------------------
* Validate capabilities (`manage_options`) and nonces (`check_admin_referer` / REST nonce) for every admin or REST action. `SyncController::canSync()` is the canonical pattern—reuse it for new routes.
* Scrub sensitive data from logs. Prefix operational logs with `[AI Hub]` and avoid dumping request bodies or tokens. For deeper telemetry, post signed payloads back to the Hub instead of local logs.
* Enforce tenant scoping: outbound payloads must include `site_id` and automation token, matching Hub expectations. If the backend adds signature or rotation requirements, update both plugin and docs in lockstep.
* Handle failures defensively—use `WP_Error` for recoverable issues, record errors through `Settings::recordError()`, and provide actionable admin notices.

4. Testing & Quality Gates
--------------------------
* **PHP:** `composer run lint` (WordPress Coding Standards) and `composer run test` (PHPUnit) must pass before shipping. Tests live under `tests/` and rely on mocks; add integration coverage for new services or controllers.
* **JavaScript:** Run `npm run lint` and `npm run typecheck` for TS/ESLint. For UI-heavy changes, add Jest/RTL or Cypress/Playwright tests (create `js/__tests__/` or `tests/e2e/` as needed) and document how to execute them.
* **Build:** Execute `npm run build` to ensure Vite outputs a fresh `dist/manifest.json`. Confirm `ai-hub-seo.php` enqueues the correct asset keys.
* **Manual QA:** Exercise sync, REST routes, and admin UI against a local WordPress instance seeded with real tenant fixtures (mask secrets). Document repro steps in the README when shipping new flows.

5. Release Process
------------------
* Update plugin metadata (`ai-hub-seo.php` header) and `README.md` when bumping versions or raising platform requirements.
* Maintain `CHANGELOG.md` (add if missing) using Keep a Changelog conventions. Reference Hub tickets/PRs and note cross-repo coordination.
* Produce release archives with a reproducible script (e.g., `npm run build && composer install --no-dev && zip`). Exclude dev-only files via `.distignore` or build tooling.
* Tag releases (`vX.Y.Z`) once CI verifies PHP lint/tests and JS build/lint runs. Publish assets to the internal registry or WordPress distribution channel per deployment SOP.

6. Documentation & Knowledge Stewardship
----------------------------------------
* Keep `README.md` authoritative: installation steps, required Hub endpoints, environment variables, and troubleshooting tips must reflect the current code.
* Mirror impactful changes in the central Hub docs (`docs/integrations/wordpress.md` in the main platform repo) so backend and plugin stay aligned.
* Update this `AGENTS.md` whenever workflow expectations shift (new scripts, folder layout, security requirements).
* When referencing Google Drive planning docs, verify they match the Phase 2 architecture before implementing and backfill updates after changes land.

7. Collaboration Checklist
--------------------------
* Check for active branches or PRs touching the plugin before starting work. Coordinate in `#wp-integration` or the designated Hub channel.
* Keep commits scoped (feature, fix, chore). Separate PHP refactors from JS/UI changes when possible.
* Request reviews from both a PHP-focused engineer and a Hub backend owner when modifying API contracts or automation-token logic.
* Record local limitations (missing remote, sandbox restrictions, unavailable Hub endpoints) in commit messages or task notes so the next contributor understands current constraints.

Operate with the goal of keeping AI Hub’s WordPress integration secure, testable, and easy to extend. When in doubt, inspect existing patterns in `src/` and mirror their approach instead of inventing new conventions.
