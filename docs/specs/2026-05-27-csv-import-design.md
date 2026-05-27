# CSV Import Design

**Date:** 2026-05-27

## Overview

Add the ability to import entries from CSV files — either a native gistats export or a Poopify export — so users can migrate from Poopify and restore from backups. Duplicate entries (same `occurred_at` UTC timestamp for the same user) are silently skipped.

## Schema Change

Add a unique index on `(user_id, occurred_at)` to enforce at the database level that no two entries for the same user share the same timestamp. This prevents duplicates regardless of how entries are created.

The migration uses `CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_occurred_unique ON entries(user_id, occurred_at)`, which is safe to run on any existing database.

## Supported Formats

Format is auto-detected from the CSV header row. No user input required.

### Native gistats format

Header: `occurred_at_utc, occurred_at_local, duration_seconds, stool_type, note`

- `occurred_at_utc` → stored as-is (already a UTC ISO string)
- `duration_seconds` → stored as-is (nullable integer)
- `stool_type` → stored as-is (integer 1–7)
- `note` → stored as-is; empty string → null
- `occurred_at_local` is ignored

### Poopify format

Header: `Date, Time, There was stool, Color, Consistency, ...`

- `Date` + `Time` → combined as `"Y-m-d H:i:s"`, interpreted as the app's configured timezone, converted to UTC
- `Consistency` → mapped to Bristol stool type 1–7 by exact string match:
  - `"Separated hard lumps"` → 1
  - `"Lumpy and sausage like"` → 2
  - `"Sausage shaped with cracks"` → 3
  - `"Like a smooth, soft sausage or snake"` → 4
  - `"Soft blobs, with clear-cut edges"` → 5
  - `"Mushy consistency with ragged edges"` → 6
  - `"Liquid, with no solid pieces"` → 7
- `There was stool` → must be `"Yes"`; rows where it is not `"Yes"` are skipped with an error message (the app has no concept of a "no stool" entry)
- `Time on toilet` → multiplied by 60 to get `duration_seconds`; empty → null
- `Extra notes` → `note`; empty string → null
- All other Poopify columns are ignored

## Import Logic

### `import_csv(int $userId, string $csvContent, string $timezone): array`

Lives in `lib/entries.php` alongside `build_csv_export`.

Returns:
```php
[
    'imported'           => int,
    'skipped_duplicates' => int,
    'errors'             => string[],  // human-readable per-row error descriptions
]
```

Steps:
1. Parse CSV using `fgetcsv` on a `php://temp` stream.
2. Read header row; detect format:
   - First column is `occurred_at_utc` → native format
   - First column is `Date` → Poopify format
   - Neither → return immediately with a single error, zero imported.
3. For each data row, normalize to: `occurred_at` (UTC string), `stool_type` (int), `duration_seconds` (int|null), `note` (string|null). Any parsing failure (bad date, unrecognized Consistency value, stool_type out of range 1–7) appends a descriptive message to `errors[]` and skips the row. No partial inserts.
4. Insert via `INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note) VALUES (?, ?, ?, ?, ?)`. If `rowCount() === 0`, increment `skipped_duplicates`.

## HTTP Layer

### `GET /import`

Requires auth. Renders `views/import.php` with a file upload form (file input + submit button). No result data on first load.

### `POST /import`

Requires auth + CSRF. Reads the uploaded file, validates it is present and non-empty, calls `import_csv`, then re-renders `views/import.php` with the result summary.

No HTMX — a standard form POST with a full-page result is appropriate for a one-shot batch operation.

## UI

### Nav drawer

Add "Import CSV" link directly below the existing "Export CSV" link.

### `views/import.php`

- File upload form (CSRF-protected, `enctype="multipart/form-data"`)
- On POST result, show summary: e.g. "Imported 142 entries. Skipped 3 duplicates."
- If there are per-row errors, list them below the summary.
- On error that aborted the entire import (unrecognized format, no file), show a single error message.

## Testing

- Unit tests in a new `tests/ImportTest.php` covering:
  - Native format: happy path, duplicate skipping, missing required fields
  - Poopify format: happy path, consistency mapping, time-on-toilet conversion, duplicate skipping, unrecognized consistency value
  - Unrecognized format header → error returned
  - Importing the same file twice → zero imported on second run
- The schema unique index is tested implicitly by the duplicate-skipping tests.
