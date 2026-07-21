# Urgency Flag Design

**Date:** 2026-07-20

## Summary

Add a boolean `urgency` flag to entries. Users toggle it on the entry form via a colored pill button; it's stored in the DB, shown as an emoji badge in the entries list, and included in CSV export/import.

## 1. Data model & migration

Add `urgency INTEGER NOT NULL DEFAULT 0` to the `entries` table.

There's no migration system in this codebase yet — the schema is created via `CREATE TABLE IF NOT EXISTS` in `DB::createSchema()`, which won't retroactively add a column to an already-deployed database. Since no production data exists yet but the schema is expected to be stable going forward, this is a good point to establish a lightweight migration pattern rather than defer it:

After the `CREATE TABLE`/`CREATE INDEX` block in `DB::createSchema()`, add an idempotent step:

1. Query `PRAGMA table_info(entries)`.
2. If no column named `urgency` is present, run `ALTER TABLE entries ADD COLUMN urgency INTEGER NOT NULL DEFAULT 0`.

This runs safely on every `createSchema()` call — a no-op on fresh databases (the column already exists from `CREATE TABLE`), and self-healing on any database that predates this change. This same pattern (check `PRAGMA table_info`, `ALTER TABLE` if missing) should be reused for future column additions.

## 2. Backend (`lib/entries.php`)

- `create_entry` / `update_entry`: accept `$data['urgency']` (truthy value from the form), cast to `0`/`1`, include in the INSERT/UPDATE statements. Defaults to `0` when absent.
- `build_csv_export`: add `urgency` as the last CSV column (`0`/`1`), sourced from `$row['urgency']`.
- `import_csv` native format (`normalize_native_row`): read `urgency` optionally — **not** added to `$requiredCols`, so CSVs exported before this change still import successfully. Missing or empty value defaults to `0`.
- `import_csv` poopify format (`normalize_poopify_row`): no source data exists for urgency in that format; always set `'urgency' => 0`.

## 3. Frontend — entry form (`views/partials/entry-form.php`, `css/input.css`)

- New CSS class `.btn-urgency` (pill shape matching `.btn-primary`/`.btn-outline`):
  - Default state: filled with `var(--color-green)`, white text.
  - `.active` state: filled with new `--color-orange: #E64A19` (Material Design "deep orange 700"), white text.
- Hidden input `name="urgency"` (value `0`/`1`) plus a visible `<button type="button" id="urgency-btn" class="btn-urgency">`, mirroring the existing `stool_type` hidden-input + button toggle pattern in `type-selector.php`.
- Label text: `Urgency 😌` by default (correcting "Ugency" → "Urgency"); toggling sets the hidden input to `1`, adds `.active`, and changes the label to `Urgency 🫪`. Clicking again reverses it.
- Layout: the row containing `Save` / `Cancel` (edit mode) or `Save` / `Reset` / timer button (new-entry mode) changes from a plain `gap` flex row to `justify-content:space-between`, with the existing buttons grouped on the left and the urgency toggle on the right — same visual line as `Save`, right-justified.
- Edit mode: toggle initializes from `$entry['urgency']`.
- `resetForm()` (new-entry mode): also resets the toggle to the default (green, `Urgency 😌`, hidden input `0`) state.

## 4. Entries list indicator (`views/partials/event-rows.php`)

When `$row['urgency']` is truthy, show a 🫪 emoji badge next to the duration/note cell. No marker is shown for non-urgent entries.

## 5. Testing plan (TDD)

- **`DBTest`**:
  - `createSchema` on a fresh DB creates `entries.urgency` defaulting to `0`.
  - Calling `createSchema` twice is a no-op — doesn't error, doesn't duplicate the column.
  - Simulating a pre-migration DB (create the `entries` table without `urgency`, then run the migration step) adds the column via `ALTER TABLE` without affecting existing rows.
- **`EntriesTest`**:
  - `create_entry` stores `urgency=1` when passed truthy, defaults to `0` when omitted.
  - `update_entry` changes `urgency`.
  - `get_entry` round-trips the value.
- **`ExportTest`**:
  - CSV header includes `urgency`.
  - Data row reflects `0`/`1` correctly.
- **`ImportTest`**:
  - Native-format import reads `urgency` when the column is present.
  - Native-format import succeeds and defaults to `0` when the column is absent (backward compatibility with older exports).
  - Poopify-format import always yields `urgency=0`.
- No PHP unit coverage for the pure-JS toggle behavior (consistent with how the existing timer button JS isn't unit tested) — verify the toggle/reset/edit-prefill behavior manually in-browser once implemented.
