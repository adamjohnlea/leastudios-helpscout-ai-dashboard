# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This file documents only what is specific to **leastudios-helpscout-ai-dashboard**. Suite-wide conventions (security checklist, WPCS, PSR-4 layout, REST/i18n conventions, packaging) live in:

- `/Users/adamlea/Herd/leastudios-plugins/CLAUDE.md` — suite overview.
- `../leastudios-dev-tools/CLAUDE.md` — the "mother" CLAUDE.md (escape/sanitize/nonce/capability rules, shared `composer` quality commands, `bin/install-wp-tests.sh`).

Read those before doing anything non-trivial here. Do not duplicate them.

## Project status

`1.0.0` released. The plugin is functional end-to-end: CSV import (admin upload + WP-CLI), Settings page (Beacon → site map), weekly Dashboard view. Phase 6 (tests + lint clean) is complete; the suite-alignment pass is the latest work.

This plugin is a port of the standalone `ai-answers-dashboard` plugin. The source still lives at `/Users/adamlea/Herd/aianswers/wp-content/plugins/ai-answers-dashboard/` for reference — including the design spec at `docs/superpowers/specs/2026-05-23-port-to-leastudios-helpscout-ai-dashboard-design.md` and the parallel plan under `docs/superpowers/plans/`. Read those before changing anything load-bearing about the schema, dedupe story, or import flow.

## What this plugin does

Ingests the CSV exports produced by Help Scout's AI Beacon and renders a per-site weekly dashboard across every Beacon a team runs. The value prop is "stop merging spreadsheets by hand for a portfolio of Beacons" — one Beacon is fine in Help Scout's native reporting.

Three surfaces:

1. **Admin upload** — `Help Scout AI → Reports` accepts one CSV at a time (or a folder via WP-CLI), runs it through the importer, and surfaces row counts + dedupe outcomes.
2. **Settings** — `Help Scout AI → Settings` maps Beacon IDs (Help Scout-issued strings like `abc123`) to friendly site names. The map is the only configuration the plugin needs.
3. **Dashboard** — `Help Scout AI → Dashboard` shows per-site weekly rollups (totals, trends, article-ref drill-down).

## Naming conventions (canonical)

| Concern | Value |
|---|---|
| Plugin slug / dir / text domain | `leastudios-helpscout-ai-dashboard` |
| Display / menu title | `leaStudios Help Scout AI Dashboard` |
| Bootstrap file | `leastudios-helpscout-ai-dashboard.php` |
| Composer namespace root | `LEAStudios\HelpScoutAIDashboard\` |
| Constants | `LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION` / `_FILE` / `_DIR` / `_URL` |
| Init function | `leastudios_helpscout_ai_dashboard_init()` |
| Hook / option / action prefix | `leastudios_helpscout_ai_dashboard_` |
| DB schema-version option | `leastudios_helpscout_ai_dashboard_db_version` |
| Beacon-map option | `leastudios_helpscout_ai_dashboard_beacon_map` |
| DB tables | `{$wpdb->prefix}leastudios_helpscout_ai_dashboard_{interactions,article_refs,reports}` |
| Capabilities | `manage_leastudios_helpscout_ai_dashboard` (write), `view_leastudios_helpscout_ai_dashboard` (read) |
| REST namespace | `leastudios-helpscout-ai-dashboard/v1` |
| WP-CLI command | `wp lshsai <subcommand>` |

## Build / test / lint commands

```bash
composer install                       # install all deps including dev tooling
composer phpcs                         # WordPress coding standards
composer phpcbf                        # auto-fix coding standards
composer phpstan                       # static analysis (scans src/)
composer lint                          # phpcs + phpstan
composer test                          # PHPUnit (WP_UnitTestCase)
```

The PHPUnit suite requires a WordPress test install. One-time setup:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '<pass>' localhost latest
```

## Architecture map

- **`leastudios-helpscout-ai-dashboard.php`** — plugin header, constants, vendor-autoload guard with admin notice, then `Plugin::init()` on `plugins_loaded`. Registers activation/deactivation hooks.
- **`src/Plugin.php`** — composition root. Loads textdomain, registers REST controllers on `rest_api_init`, instantiates Admin pages in wp-admin, registers WP-CLI commands when `WP_CLI` is defined.
- **`src/Activation.php`** / **`src/Deactivation.php`** — install schema + grant caps; clean up on deactivate.
- **`src/Capabilities.php`** — the two capability constants gating each page and REST route.
- **`src/Database/Schema.php`** — owns the three custom tables (`dbDelta()` install, drop, table-name accessors). Schema version tracked in `option('leastudios_helpscout_ai_dashboard_db_version')` for future migrations.
- **`src/CSV/Importer.php`** — CSV parsing, ISO-week bucketing, file-hash dedupe, per-row dedupe on `(session_id, occurred_at)`, article-ref normalization. Shared by the REST upload endpoint and the WP-CLI commands so there is one code path per ingest.
- **`src/REST/Reports_Controller.php`** — `POST /reports` (upload), `DELETE /reports/{id}` (cascade delete), `GET /dashboard` (aggregated read). Holds the `REST_NAMESPACE` constant.
- **`src/REST/Settings_Controller.php`** — Beacon-map read/write endpoints used by the Settings page.
- **`src/Admin/Admin.php`** — wires the Dashboard / Reports / Settings submenus under a single "Help Scout AI" top-level menu; enqueues page-scoped assets.
- **`src/CLI/Import_Command.php`** — thin wrappers around `Importer` for `wp lshsai import-file` and `wp lshsai import-folder` (non-recursive). Both honor the same dedupe rules as the admin upload.
- **`src/Shared/Week_Helper.php`** — ISO-week bucketing used by the importer and the dashboard query.
- **`templates/`** — plain PHP partials rendered with output buffering. No Twig.

### Database tables

All three are prefixed `{$wpdb->prefix}leastudios_helpscout_ai_dashboard_`:

| Table | Purpose | Dedupe key |
|---|---|---|
| `interactions` | One row per Help Scout AI Beacon Q&A turn. | `(session_id, occurred_at)` — overlapping CSV exports merge instead of double-counting. |
| `article_refs` | Normalized article references attached to interactions (1–3 per row typical). | `(interaction_id, article_id)` — a single article surfaced across many interactions stores metadata once. |
| `reports` | One row per uploaded CSV file (filename, hash, size, row counts, timestamp). | SHA-256 of file contents — re-uploading the same file is a no-op. |

Deletion of a `reports` row cascades through `interactions` and `article_refs`.

All datetimes are stored in UTC and converted to the WordPress display timezone in the admin UI via `get_date_from_gmt()`.

## Tests

PHPUnit 9.6 with the WP test suite. Tests extend a shared abstract `tests/TestCase.php` (namespace `LEAStudios\Tests` — matches the sibling pattern). `TestCase::setUp()` calls `Schema::install()` so every test starts against the live tables; `WP_UnitTestCase` rolls back row state per test.

- `tests/Unit/` — pure PHPUnit-style tests (CSV importer happy paths, schema install, week helper).
- `tests/Integration/` — REST controller tests with nonces + capability enforcement.
- `tests/Fixtures/csv/` — canonical CSV fixtures (happy, malformed, multi-Beacon, etc.).

## House rules (carried over from `~/.claude/CLAUDE.md`)

- No fallbacks or workarounds without explicit written approval; silence is not approval.
- One code path per action — admin upload, REST upload, and WP-CLI all go through `CSV\Importer`.
- Surface every error to the user; never silently swallow exceptions.
- No backwards-compatibility shims for code that has not shipped.
- Never run destructive git commands (`reset --hard`, `checkout --`, `restore`, `revert`, `clean`, force push, `commit --amend`) without explicit written approval.

## Releases

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`) that auto-generates release notes from the commit log between the previous and current tag.

**To cut a release:** bump the `Version:` header in the main plugin file, commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the header, builds the zip with `composer install --no-dev`, and publishes the release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes** (use these prefixes for changes you don't want surfaced): `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

The subject text after the prefix becomes the bullet verbatim, with the first letter capitalized. To override auto-notes for a specific release, edit the body in the GitHub UI after publish.
