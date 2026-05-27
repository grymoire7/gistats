# CSV Import Design

**Date:** 2026-05-27

## Overview

Add the ability to import entries from CSV files — either a native gistats
export or a Poopify export — so users can migrate from Poopify and restore from
backups. Duplicate entries (same `occurred_at` UTC timestamp for the same user)
are silently skipped.

## Schema Change

Add a unique index on `(user_id, occurred_at)` to enforce at the database level
that no two entries for the same user share the same timestamp. This prevents
duplicates regardless of how entries are created.

The migration uses `CREATE UNIQUE INDEX IF NOT EXISTS
idx_entries_user_occurred_unique ON entries(user_id, occurred_at)`, which is
safe to run on any existing database.

## Supported Formats

Format is auto-detected from the CSV header row. No user input required.

### Native gistats format

Header: `occurred_at_utc, occurred_at_local, duration_seconds, stool_type, note`

Required columns: `occurred_at_utc`, `duration_seconds`, `stool_type`, `note`.
If any required column is absent from the header, the entire import is rejected
with an upfront error before any rows are processed.

- `occurred_at_utc` → stored as-is (already a UTC ISO string)
- `duration_seconds` → stored as-is (nullable integer)
- `stool_type` → must be a numeric integer in range 1–7; non-numeric or
  out-of-range values produce a per-row error and skip that row
- `note` → stored as-is; empty string → null
- `occurred_at_local` is ignored

### Poopify format

Header: `Date, Time, There was stool, Color, Consistency, ...`

Required columns: `Date`, `Time`, `There was stool`, `Consistency`,
`Time on toilet`, `Extra notes`. If any required column is absent from the
header, the entire import is rejected with an upfront error.

- `Date` + `Time` → combined as `"Y-m-d H:i:s"`, interpreted as the app's
  configured timezone, converted to UTC
- `Consistency` → mapped to Bristol stool type 1–7 by exact string match:
  - `"Separated hard lumps"` → 1
  - `"Lumpy and sausage like"` → 2
  - `"Sausage shaped with cracks"` → 3
  - `"Like a smooth, soft sausage or snake"` → 4
  - `"Soft blobs, with clear-cut edges"` → 5
  - `"Mushy consistency with ragged edges"` → 6
  - `"Liquid, with no solid pieces"` → 7
- `There was stool` → must be `"Yes"`; rows where it is not `"Yes"` are
  silently skipped (not reported in errors)
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
3. Validate that all required columns for the detected format are present in the
   header. If any are missing, return immediately with a single error listing the
   missing columns.
4. For each data row, delegate to a format-specific normalizer:
   - `normalize_native_row()` for native format
   - `normalize_poopify_row()` for Poopify format
5. Insert via `INSERT OR IGNORE INTO entries ...`. If `rowCount() === 0`,
   increment `skipped_duplicates`.

### `normalize_native_row(array $row, array $colIdx, int $rowNum): array|string`

Parses one row from a native-format CSV. Returns a normalized data array on
success, or a human-readable error string on failure. The caller skips the row
in either error case.

### `normalize_poopify_row(array $row, array $colIdx, int $rowNum, string $timezone): array|string|null`

Parses one row from a Poopify-format CSV. Returns:
- A normalized data array on success
- A human-readable error string on failure (unrecognized consistency, invalid
  date) — caller appends to `errors[]` and skips
- `null` if `There was stool` is not `"Yes"` — caller silently skips, no error

The normalized array from both functions has the shape:
```php
[
    'occurred_at'      => string,   // UTC ISO
    'stool_type'       => int,
    'duration_seconds' => int|null,
    'note'             => string|null,
]
```

## Duplicate handling in `create_entry` and `update_entry`

With the unique index in place, submitting the entry form twice at the same
second would produce a raw database exception. Both functions must handle this
gracefully.

### `create_entry` change

Switch to `INSERT OR IGNORE` and return `?int`: the new entry ID on success,
or `null` if the timestamp already exists for this user. Callers treat `null`
as a duplicate.

### `update_entry` change

Wrap the `UPDATE` in a try-catch for `PDOException`. If the exception message
contains `"UNIQUE constraint failed"`, return `null` (duplicate). Otherwise
re-throw. Existing return values (`true` = updated, `false` = not found) are
unchanged.

### Route handler changes

Both `POST /entries` and `POST /entries/:id` check for a `null` return and
render a flash error: **"An entry already exists at this time."** No entry is
created or modified.

## HTTP Layer

### `GET /import`

Requires auth. Renders `views/import.php` with a file upload form (file input +
submit button). No result data on first load.

### `POST /import`

Requires auth + CSRF. Reads the uploaded file, validates it is present and
non-empty, calls `import_csv`, then re-renders `views/import.php` with the
result summary.

No HTMX — a standard form POST with a full-page result is appropriate for a
one-shot batch operation.

## UI

### Nav drawer

Add "Import CSV" link directly below the existing "Export CSV" link.

### `views/import.php`

- File upload form (CSRF-protected, `enctype="multipart/form-data"`)
- On POST result, show summary: e.g. "Imported 142 entries. Skipped 3 duplicates."
- If there are per-row errors, list them below the summary.
- On error that aborted the entire import (unrecognized format, missing columns,
  no file), show a single error message.

## Testing

- Unit tests in a new `tests/ImportTest.php` covering:
  - Native format: happy path, duplicate skipping, invalid stool_type (out of
    range, non-numeric, zero), missing required column in header
  - Poopify format: happy path (consistency mapping, time-on-toilet, note,
    UTC conversion), duplicate skipping, unrecognized consistency, "There was
    stool" != "Yes" silently skipped, missing required column in header
  - Unrecognized format header → error returned
  - Native round-trip: `build_csv_export` → `import_csv` → zero re-imported
  - Header-only CSV (zero data rows) → zero imported, zero errors
  - Importing the same file twice → zero imported on second run
- `tests/EntriesTest.php` extended with:
  - `create_entry` returns null on duplicate timestamp
  - `update_entry` returns null on conflicting timestamp
- The schema unique index is verified by the duplicate-skipping tests.
