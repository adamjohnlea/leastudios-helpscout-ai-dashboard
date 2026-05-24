# leaStudios Help Scout AI Dashboard — Developer Handbook

leaStudios Help Scout AI Dashboard ingests CSV exports from Help Scout's AI
Beacon and renders a per-site weekly analytics dashboard across every Beacon a
team operates. It is entirely REST-driven: three JSON endpoints feed the admin
frontend, two WP-CLI commands handle bulk backfill, and a single `Importer`
class enforces consistent dedupe whether data arrives via browser upload, REST
call, or CLI. Extension authors integrate through those REST endpoints and CLI
commands — there are no PHP hooks to register.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Development Setup](#3-development-setup)
4. [Concepts](#4-concepts)
5. [Data Model](#5-data-model)
6. [REST API Reference](#6-rest-api-reference)
7. [WP-CLI Commands](#7-wp-cli-commands)
8. [Public PHP API](#8-public-php-api)
9. [Extension Recipes](#9-extension-recipes)
10. [Testing](#10-testing)
11. [Release Process](#11-release-process)
12. [Where to Read More](#12-where-to-read-more)

---

## 1. Overview

leaStudios Help Scout AI Dashboard solves the "portfolio Beacon problem": Help
Scout's native reporting covers a single Beacon fine, but teams running multiple
Beacons across several sites have to download one CSV per Beacon and merge them
by hand each week. This plugin eliminates that by accepting those CSVs (via admin
upload or WP-CLI bulk import), deduplicating across overlapping date ranges, and
rendering a unified weekly dashboard with per-site rollups, trend lines, and
article-reference drill-downs.

The plugin tracks three kinds of data: **interactions** (one row per Help Scout
AI Q&A turn), **article refs** (up to three knowledge-base articles surfaced per
interaction), and **reports** (one row per uploaded CSV, used to audit the import
history and cascade-delete by source). A **Beacon map** — stored as a WordPress
option — translates the opaque Beacon UUIDs that Help Scout embeds in every CSV
row into friendly site names like "CG Cookie" or "Superhive Docs".

Two custom capabilities gate every surface: `manage_leastudios_helpscout_ai_dashboard`
(write: upload, delete, configure) and `view_leastudios_helpscout_ai_dashboard`
(read: dashboard, reports list, settings read). Administrators receive both on
activation; Editors receive read-only access.

---

## 2. Architecture

*This plugin exposes no PHP hooks; integration is REST-only.*

```
leastudios-helpscout-ai-dashboard.php
    └── Plugin::init()  (on plugins_loaded)
            |
            ├── rest_api_init
            |       ├── Reports_Controller::register_routes()
            |       │       ├── GET  /reports        list_reports()
            |       │       ├── POST /reports        upload_report()  ─┐
            |       │       ├── DELETE /reports/{id} delete_report()   │
            |       │       └── GET  /dashboard      get_dashboard()   │
            |       │                                                   │
            |       └── Settings_Controller::register_routes()         │
            |               ├── GET  /settings       get_settings()    │
            |               └── POST /settings       put_settings()    │
            |                                                           │
            ├── WP_CLI (when WP_CLI is defined)                        │
            |       ├── wp lshsai import-file ────────────────────────┤
            |       └── wp lshsai import-folder ──────────────────────┤
            |                                                           │
            └── Admin (when is_admin())                                │
                    └── Dashboard / Reports / Settings pages           │
                            (admin upload also calls Importer) ────────┘
                                                                       │
                                                          CSV\Importer (one code path)
                                                                │
                                                    Database\Schema (three tables)
```

**Key design rule:** every ingest path — REST `POST /reports`, admin upload, and
both WP-CLI commands — calls `CSV\Importer::import()`. There is exactly one code
path for parsing, deduplication, and persistence.

**Components:**

- `src/Plugin.php` — composition root. Registers REST controllers, Admin pages,
  and WP-CLI commands on the appropriate WordPress hooks.
- `src/REST/Reports_Controller.php` — four routes covering the full reports
  lifecycle (list, upload, delete) plus the aggregated dashboard payload.
  Holds the `REST_NAMESPACE` constant used by every other class that needs
  the API root.
- `src/REST/Settings_Controller.php` — two routes for reading and writing the
  Beacon → site map.
- `src/CSV/Importer.php` — streaming CSV parser. Reads the Beacon map from the
  option store on construction, validates header columns, dedupes on
  `(session_id, occurred_at)` via `INSERT IGNORE`, and attaches article refs
  in a separate batched insert.
- `src/Database/Schema.php` — owns the three custom tables and the option
  constants. All table names go through static accessor methods — nothing else
  constructs them by hand.
- `src/Capabilities.php` — the two capability string constants. Every
  `permission_callback` and capability check uses these; the strings never
  appear as literals outside this file.
- `src/Shared/Week_Helper.php` — ISO-week bucketing in America/Chicago time,
  matching the legacy Python script's `week_ending_for` logic.
- `src/CLI/Import_Command.php` — thin wrappers around `Importer` for the two
  WP-CLI subcommands.
- `src/Admin/Admin.php` — wires three admin submenus under "Help Scout AI" and
  enqueues page-scoped assets (`dashboard.js`, `reports.js`, `settings.js`).

**Frontend:** the Dashboard page loads `assets/vendor/chart.umd.min.js`
(Chart.js 4.4.1, bundled locally) plus `assets/js/dashboard.js`. The Reports
and Settings pages are lighter. All three pages receive `wp_localize_script`
data under the `LSHSAID` global: the REST root URL, a `wp_rest` nonce, and the
Reports admin page URL. The JS reads and writes via the REST API; there are no
`admin-ajax.php` or form-POST code paths.

**ETag caching:** `GET /dashboard` computes a cheap fingerprint (max interaction
id + counts + max upload timestamp + beacon-map hash) and returns `304 Not
Modified` when the client's `If-None-Match` header matches. This keeps the
dashboard snappy when the dataset is large and unchanged.

---

## 3. Development Setup

```bash
cd wp-content/plugins/leastudios-helpscout-ai-dashboard
composer install
composer lint              # phpcs + phpstan (level 6)
composer test              # PHPUnit 9.6
```

Install the shared WordPress test library once before running `composer test`:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

No sibling plugins are required. To exercise the full stack against the local
Herd site:

```bash
wp plugin activate leastudios-helpscout-ai-dashboard

# Upload a CSV via CLI:
wp lshsai import-file /path/to/ai_interactions_export.csv

# Confirm the dashboard REST endpoint returns data:
curl -s -u admin:pw \
  https://leastudios-plugins.test/wp-json/leastudios-helpscout-ai-dashboard/v1/dashboard \
  | jq '.rows | length'
```

A Help Scout account is not required for development — the plugin only reads
local CSV files. Fixture CSVs live in `tests/Fixtures/csv/` and cover the
happy path, malformed rows, multi-Beacon data, and duplicate uploads.

---

## 4. Concepts

### Beacon

A Help Scout AI Beacon is an in-app chat widget identified by a UUID string like
`3385ee56-3426-497b-8421-a461de52b28b`. Help Scout assigns one Beacon per
product surface (one for the main docs site, another for the app itself, etc.).
CSV exports include the raw Beacon UUID in every row; the **Beacon map** option
translates those UUIDs into the friendly site names shown in the dashboard.

### Interaction

One Q&A turn between a visitor and the AI Beacon. Maps to a row in the
`interactions` table and to one row in the CSV export. Identified by
`(session_id, occurred_at)` — the pair is unique per the Help Scout schema, so
the plugin uses it as the dedupe key.

### Session

A conversation between one visitor and the Beacon. A session may contain
multiple interactions (the visitor asks follow-up questions). The `session_id`
column in the CSV links interactions that belong to the same conversation thread.

### Report

One uploaded CSV file. Tracked in the `reports` table with a SHA-1 file hash so
re-uploading the same file is a no-op. Deleting a report cascades through
`article_refs` and `interactions` so the dataset stays consistent.

### Beacon Map

A WordPress option (`leastudios_helpscout_ai_dashboard_beacon_map`) that maps
Beacon UUIDs to friendly site names. Written via `POST /settings` and read by
both the REST dashboard endpoint and the `Importer` at construction time. If a
row's Beacon UUID is absent from the map, the site column is set to
`"Unknown (<uuid>)"` so unknown Beacons are visible rather than silently
discarded.

### Week-Ending Thursday

Interactions are bucketed by the ISO-week Thursday in America/Chicago (Central)
time. This matches the convention in the legacy Python script and the dashboard
JavaScript, so data imported from the old system aligns with data from the new
one. The `Week_Helper` class owns this computation.

### Article Ref

A knowledge-base article that the AI surfaced alongside its answer. Up to three
article refs per interaction, stored in the normalized `article_refs` table.
Article refs are attached only to newly inserted interactions — re-imports of
duplicate rows do not double-count article appearances.

---

## 5. Data Model

### Custom Tables

Three tables are created on activation via `dbDelta()`, all prefixed
`{$wpdb->prefix}leastudios_helpscout_ai_dashboard_`. Use `Schema::table_*()`
accessors to get fully-qualified names — never construct the strings by hand.

| Accessor | Logical suffix | Purpose | Dedupe key |
|---|---|---|---|
| `Schema::table_reports()` | `reports` | One row per CSV upload. | `file_hash` (SHA-1) — re-uploading the same file is a no-op. |
| `Schema::table_interactions()` | `interactions` | One row per Help Scout AI Beacon Q&A turn. | `(session_id, occurred_at)` — overlapping exports merge instead of double-counting. |
| `Schema::table_article_refs()` | `article_refs` | Up to 3 articles surfaced per interaction. | No unique constraint; articles are only inserted for newly kept interactions. |

**`reports` columns (key subset):**

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key. |
| `filename` | `VARCHAR(255)` | Original filename as uploaded. |
| `file_hash` | `CHAR(40)` | SHA-1; unique key used for dedupe. |
| `uploaded_by` | `BIGINT UNSIGNED` | WP user id (0 for CLI imports). |
| `uploaded_at` | `DATETIME` | UTC. |
| `row_count` | `INT` | Interactions kept (after dedupe). |
| `dupes_skipped` | `INT` | Interactions rejected as duplicates. |
| `date_min` / `date_max` | `DATE` | Central-time date range of kept rows. |
| `sites_json` | `TEXT` | JSON array of distinct site names in this file. |
| `notes` | `VARCHAR(255)` | Free-text; set by CLI `--notes` flag. |

**`interactions` columns (key subset):**

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key. |
| `session_id` | `VARCHAR(64)` | Help Scout session identifier. |
| `occurred_at` | `DATETIME` | UTC; unique with `session_id`. |
| `week_ending` | `DATE` | ISO-week Thursday in Central time. |
| `beacon_id` | `VARCHAR(64)` | Raw Beacon UUID from the CSV. |
| `site` | `VARCHAR(64)` | Resolved site name from the Beacon map. |
| `question` / `answer` | `TEXT` / `LONGTEXT` | Raw AI exchange. |
| `answer_type` | `VARCHAR(32)` | Help Scout classification (e.g. `ai`). |
| `rating` / `comment` | `VARCHAR(16)` / `TEXT` | Visitor feedback. |
| `conversation_url` | `VARCHAR(255)` | Link to the Help Scout conversation. |
| `report_id` | `BIGINT UNSIGNED` | FK to `reports.id`. |

**`article_refs` columns:**

| Column | Type | Notes |
|---|---|---|
| `interaction_id` | `BIGINT UNSIGNED` | FK to `interactions.id`. |
| `position` | `TINYINT UNSIGNED` | 1, 2, or 3 — order the AI surfaced articles. |
| `title` | `VARCHAR(512)` | Article title. |
| `url` | `VARCHAR(1024)` | Article URL. |

### Options

| Option key | Type | Description |
|---|---|---|
| `leastudios_helpscout_ai_dashboard_beacon_map` | `array<string,string>` | Beacon UUID → site name map. Seeded on fresh install with four production Beacons; written via `POST /settings`. |
| `leastudios_helpscout_ai_dashboard_db_version` | `string` | Current schema version (`"1"`). Written by `Schema::install()` for future migration checks. |

---

## 6. REST API Reference

Namespace: `leastudios-helpscout-ai-dashboard/v1`

All routes return JSON. The `wp_rest` nonce must be sent in the
`X-WP-Nonce` header for every request from the browser. REST API
authentication (Application Passwords, JWT, etc.) is also accepted for
programmatic clients.

| Method | Route | Description | Capability |
|---|---|---|---|
| `GET` | `/reports` | Paginated list of uploaded reports. | `view_leastudios_helpscout_ai_dashboard` |
| `POST` | `/reports` | Upload a new Help Scout AI CSV. | `manage_leastudios_helpscout_ai_dashboard` |
| `DELETE` | `/reports/{id}` | Cascade-delete a report and its data. | `manage_leastudios_helpscout_ai_dashboard` |
| `GET` | `/dashboard` | Full aggregated payload for the dashboard frontend. | `view_leastudios_helpscout_ai_dashboard` |
| `GET` | `/settings` | Read the current Beacon → site map. | `view_leastudios_helpscout_ai_dashboard` |
| `POST` | `/settings` | Write a new Beacon → site map. | `manage_leastudios_helpscout_ai_dashboard` |

---

### `GET /reports`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/reports`
- **Controller:** `src/REST/Reports_Controller.php` → `list_reports()`
- **Capability:** `view_leastudios_helpscout_ai_dashboard`
- **Query parameters:**

  | Parameter | Type | Default | Description |
  |---|---|---|---|
  | `page` | `int` | `1` | Page number (1-based). Clamped to the last page if the client requests beyond it. |
  | `per_page` | `int` | `25` | Rows per page. Clamped to `[1, 200]`. |

- **Response (200):**

  ```json
  {
    "rows": [
      {
        "id": 7,
        "filename": "ai_interactions_2026-04.csv",
        "uploaded_at": "2026-04-30 18:42:00",
        "uploaded_by": 1,
        "row_count": 1423,
        "dupes_skipped": 12,
        "date_min": "2026-04-01",
        "date_max": "2026-04-29",
        "sites": ["CG Cookie", "Superhive"],
        "notes": ""
      }
    ],
    "total": 7,
    "page": 1,
    "per_page": 25,
    "total_pages": 1
  }
  ```

- **Example:**

  ```bash
  curl -u admin:pw \
    "https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/reports?page=1&per_page=10"
  ```

---

### `POST /reports`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/reports`
- **Controller:** `src/REST/Reports_Controller.php` → `upload_report()`
- **Capability:** `manage_leastudios_helpscout_ai_dashboard`
- **Query parameters:** none
- **Request body:** `multipart/form-data` with a single field `file` containing
  the CSV. The CSV must include these header columns: `Session ID`, `Date`,
  `Beacon ID`, `Question`, `Answer`, `Answer Type`, `Session Resolution`.
- **Response (200):**

  ```json
  {
    "report_id": 8,
    "rows": 512,
    "dupes": 3,
    "date_min": "2026-05-01",
    "date_max": "2026-05-20",
    "sites": ["CG Cookie", "CG Cookie Docs"],
    "hash": "a3f5c9..."
  }
  ```

  When the file hash matches an already-imported file, the response includes
  `"skipped_reason": "duplicate-upload"` and `"rows": 0`.

- **Response (400):** `{"error": "<reason>"}` — no file field, upload error code,
  unreadable file, missing required CSV column.

- **Example:**

  ```bash
  curl -u admin:pw \
    -F "file=@/path/to/ai_interactions_2026-05.csv" \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/reports
  ```

---

### `DELETE /reports/{id}`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/reports/{id}`
- **Controller:** `src/REST/Reports_Controller.php` → `delete_report()`
- **Capability:** `manage_leastudios_helpscout_ai_dashboard`
- **Route parameter:**

  | Parameter | Type | Validation |
  |---|---|---|
  | `id` | `int` | Must be a positive integer. |

- **Request body:** none
- **Response (200):**

  ```json
  { "deleted": 8 }
  ```

  Deletion cascades: `article_refs` rows linked to the report's interactions are
  removed first, then the `interactions` rows, then the `reports` row. This
  matches the join-based cascade in the source plugin and keeps the dataset
  consistent.

- **Example:**

  ```bash
  curl -u admin:pw -X DELETE \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/reports/8
  ```

---

### `GET /dashboard`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/dashboard`
- **Controller:** `src/REST/Reports_Controller.php` → `get_dashboard()`
- **Capability:** `view_leastudios_helpscout_ai_dashboard`
- **Query parameters:** none
- **Request headers (optional):**

  | Header | Description |
  |---|---|
  | `If-None-Match` | ETag from a previous response. Returns `304 Not Modified` when the dataset is unchanged. |

- **Response (200):**

  ```json
  {
    "generated_at": "2026-05-24T14:00:00+00:00",
    "generated_at_display": "2026-05-24 14:00:00",
    "timezone": "America/Chicago",
    "reports": [
      {
        "id": 1,
        "name": "ai_interactions_2026-04.csv",
        "rows": 1423,
        "dupes": 12,
        "from": "2026-04-01",
        "to": "2026-04-29",
        "mtime": "2026-04-30 18:42:00",
        "sites": ["CG Cookie"]
      }
    ],
    "rows": [
      {
        "sid": "abc123",
        "date": "2026-04-15T09:32:00Z",
        "q": "How do I reset my password?",
        "a": "Click Forgot Password on the login screen.",
        "type": "ai",
        "cust": "42",
        "res": "resolved",
        "end": "user_closed",
        "rating": "thumbs_up",
        "comment": "",
        "arts": [{"t": "Reset Password Guide", "u": "https://docs.example.com/reset"}],
        "conv": "https://secure.helpscout.net/conversations/12345",
        "src": "report:1",
        "beacon": "3385ee56-3426-497b-8421-a461de52b28b",
        "site": "CG Cookie",
        "week": "2026-04-17"
      }
    ],
    "sites": ["CG Cookie", "CG Cookie Docs", "Superhive", "Superhive Docs"],
    "beacon_map": {
      "3385ee56-3426-497b-8421-a461de52b28b": "CG Cookie"
    },
    "weeks": ["2026-04-03", "2026-04-10", "2026-04-17"]
  }
  ```

  The `ETag` and `Cache-Control: private, must-revalidate` response headers are
  always present. The ETag fingerprint covers max interaction id, interaction
  count, max upload timestamp, report count, and a hash of the Beacon map — it
  changes whenever any of those change.

- **Example:**

  ```bash
  # First fetch — capture the ETag.
  curl -i -u admin:pw \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/dashboard \
    | head -20

  # Subsequent fetch — conditional; returns 304 if unchanged.
  curl -u admin:pw \
    -H 'If-None-Match: "a1b2c3d4e5f6a7b8"' \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/dashboard
  ```

---

### `GET /settings`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/settings`
- **Controller:** `src/REST/Settings_Controller.php` → `get_settings()`
- **Capability:** `view_leastudios_helpscout_ai_dashboard`
- **Query parameters:** none
- **Request body:** none
- **Response (200):**

  ```json
  {
    "beacon_map": {
      "3385ee56-3426-497b-8421-a461de52b28b": "CG Cookie",
      "9c45c870-83e9-4e22-870b-3c2d24bd8a4f": "CG Cookie Docs"
    }
  }
  ```

- **Example:**

  ```bash
  curl -u admin:pw \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/settings
  ```

---

### `POST /settings`

- **Endpoint:** `/wp-json/leastudios-helpscout-ai-dashboard/v1/settings`
- **Controller:** `src/REST/Settings_Controller.php` → `put_settings()`
- **Capability:** `manage_leastudios_helpscout_ai_dashboard`
- **Query parameters:** none
- **Request body:** `application/json`

  ```json
  {
    "beacon_map": {
      "3385ee56-3426-497b-8421-a461de52b28b": "CG Cookie",
      "9c45c870-83e9-4e22-870b-3c2d24bd8a4f": "CG Cookie Docs",
      "cc7ce422-91cb-4d72-9b94-cd45894f2fe7": "Superhive",
      "f9c9fe3d-8a95-466d-aca7-0264cf304f53": "Superhive Docs"
    }
  }
  ```

  Any entry whose key or value is an empty string (after trimming) is silently
  dropped. Non-scalar values are dropped. The entire map is replaced atomically
  on each write.

- **Response (200):** the sanitized map that was persisted, in the same shape as
  `GET /settings`.

- **Response (400):** `{"error": "beacon_map must be an object"}` if the body
  is missing or `beacon_map` is not a JSON object.

- **Example:**

  ```bash
  curl -u admin:pw \
    -H "Content-Type: application/json" \
    -d '{"beacon_map":{"3385ee56-3426-497b-8421-a461de52b28b":"CG Cookie"}}' \
    https://example.com/wp-json/leastudios-helpscout-ai-dashboard/v1/settings
  ```

---

## 7. WP-CLI Commands

All subcommands live under `wp lshsai`. They share the same `CSV\Importer` used
by the REST upload endpoint — dedupe rules and the Beacon map are applied
identically regardless of ingestion path.

---

### `wp lshsai import-file`

- **File:** `src/CLI/Import_Command.php` → `import_file()`
- **Synopsis:** `wp lshsai import-file <file> [--notes=<notes>]`
- **Description:** Import a single Help Scout AI CSV file. The file must be
  readable by the web-server process. On success, prints the report id, rows
  kept, and duplicates skipped. If the file was already imported (same SHA-1
  hash), emits a warning and exits without inserting data.
- **Options:**

  | Option | Type | Default | Description |
  |---|---|---|---|
  | `<file>` | positional | required | Absolute path to the CSV file. |
  | `--notes` | string | `"wp-cli import-file"` | Free-text note recorded in the `reports.notes` column. |

- **Exit codes:** `0` on success (including duplicate-upload skip); non-zero
  (`WP_CLI::error()`) on unreadable file or parse failure.

- **Example:**

  ```bash
  wp lshsai import-file /var/exports/ai_interactions_2026-04.csv
  # Report #3 — 1423 rows kept, 12 dupes skipped.

  wp lshsai import-file /var/exports/ai_interactions_2026-04.csv --notes="backfill Q2 2026"
  # Warning: Report #3 — skipped (duplicate-upload).
  ```

---

### `wp lshsai import-folder`

- **File:** `src/CLI/Import_Command.php` → `import_folder()`
- **Synopsis:** `wp lshsai import-folder <folder> [--dry-run]`
- **Description:** Bulk-import every file matching `ai_interactions*.csv` in the
  given folder (non-recursive, sorted alphabetically). Reports per-file outcomes
  as it goes, then prints a summary of total rows kept and duplicates skipped
  across all files. Duplicate files are logged as skipped rather than treated as
  errors. File-level parse errors emit a warning and continue to the next file
  rather than aborting the batch.
- **Options:**

  | Option | Type | Default | Description |
  |---|---|---|---|
  | `<folder>` | positional | required | Absolute path to the folder containing CSVs. |
  | `--dry-run` | flag | off | Print which files would be imported without writing anything to the database. |

- **Exit codes:** `0` always (individual file errors emit warnings, not errors).
  Non-zero only if the folder path is invalid (`WP_CLI::error()`).

- **Example:**

  ```bash
  # Preview what would be imported.
  wp lshsai import-folder /var/exports/helpscout --dry-run
  # [dry-run] 6 file(s) queued.
  #  - would import ai_interactions_2026-01.csv
  #  - would import ai_interactions_2026-02.csv
  #  ...

  # Run the actual import.
  wp lshsai import-folder /var/exports/helpscout
  #  - ai_interactions_2026-01.csv: 1102 rows kept, 0 dupes
  #  - ai_interactions_2026-02.csv: 987 rows kept, 8 dupes
  #  - ai_interactions_2026-03.csv: skipped (duplicate-upload)
  # Success: Imported 2089 rows total (8 dupes skipped) across 3 files.
  ```

---

## 8. Public PHP API

### `LEAStudios\HelpScoutAIDashboard\CSV\Importer` *(final class)*

- **File:** `src/CSV/Importer.php`
- **Since:** 1.0.0
- **Purpose:** The single, authoritative ingest path for Help Scout AI Beacon
  CSVs. Construct once per import batch and call `import()` for each file.
  Reads the Beacon map from the option store at construction time.

**Method:**

| Method | Signature | Description |
|---|---|---|
| `import` | `( string $path, string $orig_name, int $user_id, string $notes ): array` | Parse and persist one CSV. Returns a result array (see below). Throws `\RuntimeException` on unreadable or invalid files. |

**Return shape of `import()`:**

```php
[
    'report_id' => int,          // id of the reports row created (or matched on duplicate).
    'rows'      => int,          // interactions inserted.
    'dupes'     => int,          // interactions skipped as duplicates.
    'date_min'  => string|null,  // earliest Central-time date in kept rows.
    'date_max'  => string|null,  // latest Central-time date in kept rows.
    'sites'     => list<string>, // distinct site names from the Beacon map.
    'hash'      => string,       // SHA-1 of the file contents.
    // Only present on duplicate-upload:
    'skipped_reason' => 'duplicate-upload',
]
```

**Example — calling the Importer from another plugin:**

```php
use LEAStudios\HelpScoutAIDashboard\CSV\Importer;

add_action( 'my_plugin_nightly_sync', function (): void {
    $csv_path = '/var/sync/helpscout/latest.csv';

    if ( ! is_readable( $csv_path ) ) {
        error_log( '[my-plugin] Help Scout CSV not readable: ' . $csv_path );
        return;
    }

    try {
        $result = ( new Importer() )->import(
            $csv_path,
            basename( $csv_path ),
            0,                           // user_id = 0 for automated imports.
            'nightly-sync'
        );
    } catch ( \RuntimeException $e ) {
        error_log( '[my-plugin] Help Scout import failed: ' . $e->getMessage() );
        return;
    }

    if ( isset( $result['skipped_reason'] ) ) {
        error_log( '[my-plugin] CSV already imported — skipping.' );
        return;
    }

    error_log( sprintf(
        '[my-plugin] Imported %d rows, %d dupes skipped.',
        $result['rows'],
        $result['dupes']
    ) );
} );
```

---

### `LEAStudios\HelpScoutAIDashboard\Database\Schema` *(final class)*

- **File:** `src/Database/Schema.php`
- **Since:** 1.0.0
- **Purpose:** Table-name accessors and option constants. Use these to query the
  plugin's tables from external code rather than constructing strings by hand.

**Constants:**

| Constant | Value | Description |
|---|---|---|
| `Schema::OPT_BEACON_MAP` | `leastudios_helpscout_ai_dashboard_beacon_map` | Option key for the Beacon → site map. |
| `Schema::OPT_DB_VERSION` | `leastudios_helpscout_ai_dashboard_db_version` | Option key for the installed schema version. |
| `Schema::DB_VERSION` | `"1"` | Current schema version string. |

**Static accessors:**

| Method | Returns | Description |
|---|---|---|
| `Schema::table_interactions()` | `string` | Fully-prefixed interactions table name. |
| `Schema::table_article_refs()` | `string` | Fully-prefixed article_refs table name. |
| `Schema::table_reports()` | `string` | Fully-prefixed reports table name. |

---

## 9. Extension Recipes

### How do I add a custom Beacon UUID → site mapping and immediately import a CSV?

**Goal:** Seed a new Beacon UUID → site-name mapping from code and then import a
waiting CSV so the new entry resolves correctly from the very first run.

**PHP API used:** `Schema::OPT_BEACON_MAP`, `Importer::import()`.

**Walkthrough:** The Beacon map is a plain WordPress option keyed by
`Schema::OPT_BEACON_MAP`. Read the current map, merge your entries, and write it
back. The `Importer` reads this option at construction time, so construct it
*after* updating the map and your new Beacon UUID will resolve immediately.
Because `Importer::import()` dedupes on the file's SHA-1 hash, running the same
import a second time is a no-op — making this pattern safe to call on every
plugin activation.

The Settings admin page and the `POST /settings` REST endpoint both read from the
same option, so the new entry appears in the UI as soon as `update_option()`
returns.

**Complete example:**

```php
use LEAStudios\HelpScoutAIDashboard\CSV\Importer;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;

function my_plugin_seed_and_import(): void {
    // 1. Merge our Beacon UUID into the map without overwriting existing entries.
    $map = (array) get_option( Schema::OPT_BEACON_MAP, [] );
    $map['a1b2c3d4-0000-0000-0000-000000000001'] = 'My New Site';
    update_option( Schema::OPT_BEACON_MAP, $map );

    // 2. Import a waiting CSV now that the Beacon resolves correctly.
    $csv_path = '/var/sync/helpscout/my-new-site-initial.csv';

    if ( ! is_readable( $csv_path ) ) {
        error_log( '[my-plugin] Initial CSV not readable: ' . $csv_path );
        return;
    }

    try {
        $result = ( new Importer() )->import(
            $csv_path,
            basename( $csv_path ),
            0,
            'activation-seed'
        );
    } catch ( \RuntimeException $e ) {
        error_log( '[my-plugin] Initial import failed: ' . $e->getMessage() );
        return;
    }

    if ( isset( $result['skipped_reason'] ) ) {
        return; // Already imported on a previous activation — fine.
    }

    error_log( sprintf(
        '[my-plugin] Seeded beacon map and imported %d rows (%d dupes skipped).',
        $result['rows'],
        $result['dupes']
    ) );
}
register_activation_hook( __FILE__, 'my_plugin_seed_and_import' );
```

---

### How do I trigger a CSV import from a scheduled WP-Cron event?

**Goal:** Automatically import fresh Help Scout CSVs that a remote sync drops
into a known folder, running on a daily WP-Cron schedule, and skip the run
entirely when the Beacon map is not yet configured.

**PHP API used:** `Schema::OPT_BEACON_MAP`, `Importer::import()`.

**Walkthrough:** Register a cron event that calls `Importer::import()` directly.
Check `Schema::OPT_BEACON_MAP` at the top of the handler: if the map is empty
there is no point importing because every row will be stored as
`"Unknown (<uuid>)"` rather than a friendly site name. This guard also prevents
the cron from running before a site administrator has completed initial setup.

Because the Importer dedupes on the file's SHA-1 hash, re-importing the same
file multiple times is safe — subsequent calls return `skipped_reason:
duplicate-upload` and write nothing. Use date-stamped filenames so each day's
file is distinct, or let the hash dedupe handle accidental re-drops.

**Complete example:**

```php
use LEAStudios\HelpScoutAIDashboard\CSV\Importer;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;

add_action( 'plugins_loaded', function (): void {
    if ( ! wp_next_scheduled( 'my_plugin_helpscout_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'my_plugin_helpscout_sync' );
    }
} );

add_action( 'my_plugin_helpscout_sync', function (): void {
    // Abort if no Beacon map has been configured — imports would produce
    // "Unknown (<uuid>)" site names and pollute the dashboard.
    $beacon_map = (array) get_option( Schema::OPT_BEACON_MAP, [] );
    if ( ! $beacon_map ) {
        error_log( '[my-plugin] Help Scout sync skipped: Beacon map is empty.' );
        return;
    }

    $folder = '/var/sync/helpscout';
    $files  = glob( $folder . '/ai_interactions*.csv' );

    if ( ! is_array( $files ) || ! $files ) {
        return;
    }

    // Sort ascending so we process files oldest-first.
    sort( $files );
    $importer = new Importer();

    foreach ( $files as $file ) {
        try {
            $result = $importer->import( $file, basename( $file ), 0, 'cron-sync' );

            if ( isset( $result['skipped_reason'] ) ) {
                continue; // Already imported — safe to skip silently.
            }

            error_log( sprintf(
                '[my-plugin] Imported %s: %d rows, %d dupes.',
                basename( $file ),
                $result['rows'],
                $result['dupes']
            ) );
        } catch ( \RuntimeException $e ) {
            error_log( '[my-plugin] Import failed for ' . basename( $file ) . ': ' . $e->getMessage() );
        }
    }
} );
```

---

### How do I query interaction rows and their article refs from PHP?

**Goal:** Read interaction rows together with the articles the AI surfaced, for a
custom report or external integration, without going through the REST API.

**PHP API used:** `Schema::table_interactions()`, `Schema::table_article_refs()`.

**Walkthrough:** Use the `Schema::table_*()` accessors to get fully-prefixed
table names, then query with `$wpdb->prepare()`. All `occurred_at` timestamps
are stored in UTC; convert to the display timezone using WordPress's
`get_date_from_gmt()` when presenting values to users.

Join `article_refs` on `interaction_id` to pull the knowledge-base articles the
AI surfaced alongside each answer. Because up to three refs per interaction are
stored as separate rows (position 1–3), group them with `GROUP_CONCAT` or collect
them in a second query. The example below uses a single JOIN and groups in PHP
for simplicity.

**Complete example:**

```php
use LEAStudios\HelpScoutAIDashboard\Database\Schema;

/**
 * Return interactions for a given week, each with its article refs attached.
 *
 * @param string $week_ending ISO-week Thursday date, e.g. "2026-04-17".
 * @return array<int, array{site: string, question: string, rating: string, articles: list<array{title: string, url: string}>}>
 */
function my_plugin_get_interactions_with_refs( string $week_ending ): array {
    global $wpdb;

    $t_int = Schema::table_interactions();
    $t_art = Schema::table_article_refs();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT i.id, i.site, i.question, i.rating,
                    a.position, a.title AS art_title, a.url AS art_url
             FROM {$t_int} AS i
             LEFT JOIN {$t_art} AS a ON a.interaction_id = i.id
             WHERE i.week_ending = %s
             ORDER BY i.id ASC, a.position ASC",
            $week_ending
        ),
        ARRAY_A
    );

    if ( ! is_array( $rows ) ) {
        return [];
    }

    // Group article refs back onto their parent interaction.
    $interactions = [];
    foreach ( $rows as $row ) {
        $id = (int) $row['id'];
        if ( ! isset( $interactions[ $id ] ) ) {
            $interactions[ $id ] = [
                'site'     => $row['site'],
                'question' => $row['question'],
                'rating'   => $row['rating'],
                'articles' => [],
            ];
        }
        if ( null !== $row['art_title'] ) {
            $interactions[ $id ]['articles'][] = [
                'title' => $row['art_title'],
                'url'   => $row['art_url'],
            ];
        }
    }

    return array_values( $interactions );
}
```

---

### How do I grant the view capability to a custom role and verify REST access?

**Goal:** Allow a custom WordPress role (e.g. `analyst`) to read the dashboard
and reports via the REST API, and confirm the capability assignment is working by
calling `GET /reports` as that user.

**REST + PHP API used:** `GET /reports`, `Capabilities::VIEW`, `Capabilities::MANAGE`.

**Walkthrough:** Grant `Capabilities::VIEW` on activation using the constant from
`src/Capabilities.php` so your code stays in sync if the capability string ever
changes. Remove it on deactivation.

To verify programmatically that a given user can reach the endpoint, dispatch a
`WP_REST_Request` for `GET /reports` under that user's identity using
`wp_set_current_user()` and `rest_get_server()->dispatch()`. This is most useful
inside an integration test, but the same pattern works in a CLI helper command.
Revoke the capability on deactivation; never rely on WordPress's built-in
capability deletion when a plugin is removed — add an `uninstall.php` hook if
you need permanent cleanup.

**Complete example:**

```php
use LEAStudios\HelpScoutAIDashboard\Capabilities;

function my_plugin_grant_analyst_caps(): void {
    $role = get_role( 'analyst' );
    if ( $role instanceof WP_Role ) {
        $role->add_cap( Capabilities::VIEW );
    }
}
register_activation_hook( __FILE__, 'my_plugin_grant_analyst_caps' );

function my_plugin_revoke_analyst_caps(): void {
    $role = get_role( 'analyst' );
    if ( $role instanceof WP_Role ) {
        $role->remove_cap( Capabilities::VIEW );
        $role->remove_cap( Capabilities::MANAGE ); // Safety — remove write cap too.
    }
}
register_deactivation_hook( __FILE__, 'my_plugin_revoke_analyst_caps' );

/**
 * Verify that a WP user can reach GET /reports via the REST API.
 * Call this from a WP-CLI command or an integration test.
 *
 * @param int $user_id The WordPress user ID to test.
 * @return bool True when the endpoint returns HTTP 200; false on 401/403.
 */
function my_plugin_verify_analyst_rest_access( int $user_id ): bool {
    $prev = get_current_user_id();
    wp_set_current_user( $user_id );

    $request = new WP_REST_Request(
        'GET',
        '/leastudios-helpscout-ai-dashboard/v1/reports'
    );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

    $response = rest_get_server()->dispatch( $request );
    $status   = $response->get_status();

    wp_set_current_user( $prev );

    return 200 === $status;
}
```

---

### How do I fetch the dashboard and reports list from a remote site?

**Goal:** Pull both the aggregated dashboard payload and the paginated reports
list from a remote WordPress install running this plugin, so a third site can
display a unified view across multiple Help Scout Beacon deployments.

**REST routes used:** `GET /dashboard`, `GET /reports`.

**Walkthrough:** Use an Application Password for authentication (WordPress 5.6+).
Send the `If-None-Match` header with the ETag from your last successful dashboard
fetch to avoid re-transferring unchanged data — the endpoint returns `304 Not
Modified` with an empty body when nothing has changed.

Fetch `GET /reports` separately to obtain import history metadata (filenames,
date ranges, row counts). These two calls complement each other: the dashboard
gives aggregated weekly data for charts; the reports list gives a per-file audit
trail. Cache both responses independently because the reports list changes only
when a new CSV is uploaded, while the dashboard fingerprint changes whenever
interactions or the Beacon map are updated.

**Complete example:**

```php
/**
 * Fetch the dashboard payload and reports list from a remote site.
 *
 * @param string $site_url      Base URL of the remote WordPress install.
 * @param string $user          WordPress username with Application Password.
 * @param string $app_password  Application Password (space-separated groups OK).
 * @return array{dashboard: array<string, mixed>|null, reports: list<array<string, mixed>>}
 */
function my_plugin_fetch_remote_data( string $site_url, string $user, string $app_password ): array {
    $base    = trailingslashit( $site_url ) . 'wp-json/leastudios-helpscout-ai-dashboard/v1/';
    $auth    = [ 'Authorization' => 'Basic ' . base64_encode( $user . ':' . $app_password ) ];
    $timeout = [ 'timeout' => 30 ];

    // --- GET /dashboard (ETag-conditional) ---
    $cached_etag = get_transient( 'my_plugin_dashboard_etag' );
    $dash_headers = $auth;
    if ( $cached_etag ) {
        $dash_headers['If-None-Match'] = $cached_etag;
    }

    $dash_response = wp_remote_get( $base . 'dashboard', array_merge( $timeout, [ 'headers' => $dash_headers ] ) );
    $dashboard     = null;

    if ( ! is_wp_error( $dash_response ) ) {
        $dash_code = wp_remote_retrieve_response_code( $dash_response );
        if ( 200 === $dash_code ) {
            $etag = wp_remote_retrieve_header( $dash_response, 'etag' );
            if ( $etag ) {
                set_transient( 'my_plugin_dashboard_etag', $etag, DAY_IN_SECONDS );
            }
            $decoded = json_decode( wp_remote_retrieve_body( $dash_response ), true );
            $dashboard = is_array( $decoded ) ? $decoded : null;
        } elseif ( 304 !== $dash_code ) {
            error_log( '[my-plugin] GET /dashboard returned HTTP ' . $dash_code );
        }
        // 304 = unchanged; $dashboard stays null and the caller uses its cached copy.
    } else {
        error_log( '[my-plugin] GET /dashboard error: ' . $dash_response->get_error_message() );
    }

    // --- GET /reports (first page) ---
    $rep_response = wp_remote_get(
        $base . 'reports?per_page=200',
        array_merge( $timeout, [ 'headers' => $auth ] )
    );
    $reports = [];

    if ( ! is_wp_error( $rep_response ) && 200 === wp_remote_retrieve_response_code( $rep_response ) ) {
        $decoded = json_decode( wp_remote_retrieve_body( $rep_response ), true );
        if ( is_array( $decoded ) && isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
            $reports = $decoded['rows'];
        }
    } else {
        error_log( '[my-plugin] GET /reports error or non-200 response.' );
    }

    return [
        'dashboard' => $dashboard,
        'reports'   => $reports,
    ];
}
```

---

### How do I delete all data for a specific site from PHP?

**Goal:** Remove all interactions (and their article refs) associated with one
site name, without deleting the entire reports those interactions came from.

**PHP API used:** `Schema::table_interactions()`, `Schema::table_article_refs()`.

**Walkthrough:** Query the `interactions` table filtered by `site`, collect the
ids, delete the matching `article_refs` rows first (they reference interaction
ids), then delete the interactions. Wrap both deletes in a transaction for
atomicity if your MySQL configuration supports it. Note: this leaves the `reports`
rows intact and may cause `row_count` to diverge from the actual interaction
count — that is acceptable for an ad-hoc cleanup but you may want to update
those columns afterwards.

**Complete example:**

```php
use LEAStudios\HelpScoutAIDashboard\Database\Schema;

function my_plugin_delete_site_data( string $site_name ): int {
    global $wpdb;

    $t_int = Schema::table_interactions();
    $t_art = Schema::table_article_refs();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $ids = $wpdb->get_col(
        $wpdb->prepare( "SELECT id FROM {$t_int} WHERE site = %s", $site_name )
    );

    if ( ! $ids ) {
        return 0;
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

    // Delete article refs first (they reference interaction ids).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$t_art} WHERE interaction_id IN ({$placeholders})",
            $ids
        )
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$t_int} WHERE id IN ({$placeholders})",
            $ids
        )
    );

    return is_int( $deleted ) ? $deleted : 0;
}
```

---

## 10. Testing

```bash
cd wp-content/plugins/leastudios-helpscout-ai-dashboard
composer test                                         # full suite
vendor/bin/phpunit --filter CsvImporterTest          # one class
vendor/bin/phpunit tests/Unit/WeekHelperTest.php     # one file
```

The suite is split between `tests/Unit/` (pure PHPUnit — importer logic, schema
install, week bucketing) and `tests/Integration/` (extends `WP_UnitTestCase` —
REST controller responses with nonce and capability enforcement). Fixture CSVs
live in `tests/Fixtures/csv/`.

`tests/TestCase.php` calls `Schema::install()` in `setUp()` so every test starts
with fresh tables. `WP_UnitTestCase` rolls back row state between tests —
`Schema::install()` is idempotent and safe to call repeatedly.

**Writing tests for code that loads this plugin:**

1. Activate `leastudios-helpscout-ai-dashboard` in your test bootstrap.
2. To test REST endpoints, create a `WP_REST_Request`, set the `X-WP-Nonce`
   header (use `wp_create_nonce('wp_rest')`), and dispatch via
   `rest_get_server()->dispatch()`.
3. To seed interaction data, call `Importer::import()` directly with a fixture
   CSV from `tests/Fixtures/csv/` — this exercises the real dedupe logic and
   produces realistic database state.
4. To test capability enforcement, use `wp_set_current_user()` with a user that
   does or does not hold `Capabilities::VIEW` / `Capabilities::MANAGE`.

---

## 11. Release Process

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`)
that auto-generates release notes from the commit log between the previous and
current tag.

**To cut a release:** bump the `Version:` header in
`leastudios-helpscout-ai-dashboard.php`, commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the `Version:` header, builds the zip with
`composer install --no-dev`, and publishes the GitHub release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes:** `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

---

## 12. Where to Read More

- [`CLAUDE.md`](../CLAUDE.md) — this plugin's repo conventions, naming tokens,
  architecture map, and capability/option/table reference.
- [`README.md`](../README.md) — user-facing overview and feature descriptions.
- [`leastudios-dev-tools/CLAUDE.md`](../../leastudios-dev-tools/CLAUDE.md) —
  suite-wide coding standards, security checklist (escape / sanitize / nonce /
  capability), and `$wpdb` conventions inherited by every plugin.
