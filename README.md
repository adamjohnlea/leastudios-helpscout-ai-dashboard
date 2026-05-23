# leaStudios Help Scout AI Dashboard

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb3)](#requirements) [![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b)](#requirements) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)

WordPress plugin that ingests Help Scout AI Beacon CSV exports and renders a unified weekly dashboard of customer interactions across every Beacon in your portfolio. Built for teams running many Beacons who would otherwise be merging spreadsheets by hand.

> **Status:** `1.0.0` released. Plugin is functional end-to-end.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.4 |
| PHP | 8.2 |
| Composer | Any recent |

## Data model

Three custom tables live under `{$wpdb->prefix}leastudios_helpscout_ai_dashboard_`:

| Table | Purpose | Dedupe key |
|---|---|---|
| `interactions` | One row per Help Scout AI Beacon Q&A turn. | `(session_id, occurred_at)` — overlapping CSV exports merge instead of double-counting. |
| `article_refs` | Normalized article references attached to interactions (typically 1–3 per row). | `(interaction_id, article_id)` — a single article surfaced across many interactions stores metadata once. |
| `reports` | One row per uploaded CSV file (filename, hash, size, row counts, timestamp). | SHA-256 of file contents — re-uploading the same file is a no-op. |

The schema version is tracked in `option('leastudios_helpscout_ai_dashboard_db_version')` for future migrations. Deletion of a `reports` row cascades through `interactions` and `article_refs` so removing a bad upload is a single click.

## Quick start

```bash
cd wp-content/plugins/leastudios-helpscout-ai-dashboard
composer install --no-dev
wp plugin activate leastudios-helpscout-ai-dashboard
```

Then in WP admin:

1. Visit **Help Scout AI → Settings** and add your Beacon IDs with friendly site names.
2. Visit **Help Scout AI → Reports** and upload a CSV export.
3. Visit **Help Scout AI → Dashboard** to see the weekly per-site rollup.

## Architecture

Source is namespaced under `LEAStudios\HelpScoutAIDashboard\` and laid out by concern:

- **`src/Plugin.php`** — bootstrap. Loads the textdomain, registers REST controllers, instantiates Admin pages in wp-admin, and registers WP-CLI commands when `WP_CLI` is defined.
- **`src/CSV/Importer.php`** — does the CSV parsing, ISO-week bucketing, file-hash dedupe, per-row dedupe on `(session_id, occurred_at)`, and the article-ref normalization. Shared by both the REST upload endpoint and the WP-CLI commands.
- **`src/REST/Reports_Controller.php`** — exposes `POST /reports` (upload), `DELETE /reports/{id}` (cascade delete), and `GET /dashboard` (the aggregated read for the dashboard view).
- **`src/REST/Settings_Controller.php`** — exposes the Beacon → site map read/write endpoints used by the Settings page.
- **`src/Admin/Admin.php`** — wires the Dashboard, Reports, and Settings submenus under a single "Help Scout AI" top-level menu and enqueues page-scoped assets.
- **`src/Database/Schema.php`** — owns the three custom tables: `dbDelta()` install, drop, and the table-name accessors used throughout the codebase.
- **`src/CLI/Import_Command.php`** — thin wrappers around `Importer` for `wp lshsai import-file` and `wp lshsai import-folder`.
- **`src/Capabilities.php`** — the capability constants gating each page and REST route.
- **`src/Activation.php`** / **`src/Deactivation.php`** — install schema, seed defaults, clean up scheduled events.

Templates are plain PHP partials under `templates/`, rendered with output buffering. No Twig. All datetimes are stored in UTC and converted to the WordPress display timezone in the admin UI via `get_date_from_gmt()`.

## WP-CLI

For scripted backfills or larger ingests:

```bash
wp lshsai import-file path/to/export.csv
wp lshsai import-folder path/to/folder
```

`import-folder` is non-recursive. Both honor the same file-hash + per-row dedupe rules as the admin upload, so re-runs are idempotent.

## Settings

Under **Help Scout AI → Settings** you assign each Beacon ID (a Help Scout-issued string like `abc123`) a human-readable site name. The map is stored in `option('leastudios_helpscout_ai_dashboard_beacon_map')` and consulted at dashboard render time to label rollups. Adding a new Beacon ID and re-running an existing upload re-labels the existing interactions in place.

## Development

```bash
composer install                         # install all deps including dev tooling
composer phpcs                           # WordPress coding standards
composer phpcbf                          # auto-fix coding standards
composer phpstan                         # static analysis (scans src/)
composer lint                            # phpcs + phpstan
composer test                            # PHPUnit (WP_UnitTestCase)
```

The PHPUnit suite requires a WordPress test install. One-time setup:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

Tests live under `tests/` and are split between unit tests (pure PHPUnit; CSV parser, dedupe logic) and integration tests (extends `WP_UnitTestCase`; REST controllers, repository round-trips, capability enforcement).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Built by [Adam Lea](https://leastudios.com) at leaStudios.
