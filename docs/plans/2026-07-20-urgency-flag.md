# Urgency Flag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a boolean `urgency` flag to entries, toggleable on the entry form, visible in the entries list, and included in CSV export/import.

**Architecture:** SQLite column added via an idempotent self-migrating step in `DB::createSchema()`. Backend functions in `lib/entries.php` thread `urgency` through create/update/export/import. Frontend is a hidden-input + toggle-button pattern (mirroring the existing `stool_type` selector), styled with a new `.btn-urgency` CSS class with green/deep-orange states.

**Tech Stack:** PHP 8+, PDO/SQLite, PHPUnit, vanilla JS, Tailwind-compiled CSS (`css/input.css` → `css/compiled.css` via `npm run build:css`).

## Global Constraints

- TDD red-green-refactor for all development (write failing test → minimal implementation → pass) — from project `CLAUDE.md`.
- Conventional commit format for all commits — from user's global `CLAUDE.md`.
- `urgency` stored as SQLite `INTEGER NOT NULL DEFAULT 0` (0/1), no native boolean type in SQLite.
- CSV import: `urgency` is optional on native-format import (not in `$requiredCols`), defaults to `0` if absent — preserves backward compatibility with pre-existing exports.
- Deep orange color: `--color-orange: #E64A19`.
- Toggle labels: `Urgency 😌` (urgency=false, green fill) / `Urgency 🫪` (urgency=true, deep-orange fill).

---

### Task 1: Self-migrating schema column

**Files:**
- Modify: `lib/db.php` (`DB::createSchema()`, lines 18-44)
- Test: `tests/DBTest.php`

**Interfaces:**
- Produces: `entries.urgency` column (`INTEGER NOT NULL DEFAULT 0`), present after any call to `DB::createSchema()` regardless of whether the table pre-existed without it.

- [ ] **Step 1: Write the failing tests**

Add to `tests/DBTest.php`:

```php
    public function testCreateSchemaAddsUrgencyColumnOnFreshDb(): void
    {
        DB::init($this->config());
        DB::createSchema();
        $cols = DB::fetchAll("PRAGMA table_info(entries)");
        $names = array_column($cols, 'name');
        $this->assertContains('urgency', $names);
    }

    public function testCreateSchemaIsIdempotentForUrgencyColumn(): void
    {
        DB::init($this->config());
        DB::createSchema();
        DB::createSchema(); // must not throw
        $cols = DB::fetchAll("PRAGMA table_info(entries)");
        $names = array_column($cols, 'name');
        $this->assertEquals(1, count(array_filter($names, fn($n) => $n === 'urgency')));
    }

    public function testCreateSchemaMigratesPreExistingEntriesTableWithoutUrgency(): void
    {
        $pdo = DB::init($this->config());
        // Simulate a pre-migration DB: entries table without the urgency column.
        $pdo->exec('
            CREATE TABLE entries (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id          INTEGER NOT NULL,
                occurred_at      TEXT    NOT NULL,
                duration_seconds INTEGER,
                stool_type       INTEGER NOT NULL,
                note             TEXT,
                created_at       TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
        ');
        $pdo->exec("INSERT INTO entries (user_id, occurred_at, stool_type) VALUES (1, '2026-05-12T14:30:00Z', 4)");

        DB::createSchema();

        $cols = DB::fetchAll("PRAGMA table_info(entries)");
        $names = array_column($cols, 'name');
        $this->assertContains('urgency', $names);

        $row = DB::fetch('SELECT urgency FROM entries WHERE user_id = 1');
        $this->assertEquals(0, (int) $row['urgency']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/DBTest.php`
Expected: FAIL — `testCreateSchemaAddsUrgencyColumnOnFreshDb` and the other two new tests fail because `urgency` doesn't exist yet.

- [ ] **Step 3: Implement the migration step**

In `lib/db.php`, modify `createSchema()`:

```php
    public static function createSchema(): void
    {
        if (self::$pdo === null) {
            throw new \LogicException('DB::init() must be called first');
        }
        self::$pdo->exec('
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                created_at    TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
            CREATE TABLE IF NOT EXISTS entries (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id          INTEGER NOT NULL REFERENCES users(id),
                occurred_at      TEXT    NOT NULL,
                duration_seconds INTEGER,
                stool_type       INTEGER NOT NULL,
                note             TEXT,
                urgency          INTEGER NOT NULL DEFAULT 0,
                created_at       TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
            CREATE INDEX IF NOT EXISTS idx_entries_user_occurred
                ON entries(user_id, occurred_at DESC);
            CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_occurred_unique
                ON entries(user_id, occurred_at);
        ');
        self::migrateAddColumnIfMissing('entries', 'urgency', 'INTEGER NOT NULL DEFAULT 0');
    }

    private static function migrateAddColumnIfMissing(string $table, string $column, string $definition): void
    {
        $cols = self::fetchAll("PRAGMA table_info($table)");
        $names = array_column($cols, 'name');
        if (!in_array($column, $names, true)) {
            self::$pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/DBTest.php`
Expected: PASS (all DBTest tests, including the 3 new ones)

- [ ] **Step 5: Commit**

```bash
git add lib/db.php tests/DBTest.php
git commit -m "feat: add self-migrating urgency column to entries table"
```

---

### Task 2: Backend create/update/get support

**Files:**
- Modify: `lib/entries.php` (`create_entry` lines 6-18, `update_entry` lines 20-39)
- Test: `tests/EntriesTest.php`

**Interfaces:**
- Consumes: `entries.urgency` column from Task 1.
- Produces: `create_entry(int $userId, array $data, string $timezone): ?int` now reads `$data['urgency']` (any truthy value, e.g. `'1'`, `1`, `true`); `update_entry(...)` same. Both cast to `0`/`1` before storing. `get_entry()` / `get_entries()` already `SELECT *`, so `urgency` flows through unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `tests/EntriesTest.php`:

```php
    public function testCreateEntryStoresUrgencyTrue(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'stool_type'  => 4,
            'urgency'     => '1',
        ], $this->tz);
        $row = DB::fetch('SELECT urgency FROM entries WHERE id = ?', [$id]);
        $this->assertEquals(1, (int) $row['urgency']);
    }

    public function testCreateEntryDefaultsUrgencyFalseWhenOmitted(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'stool_type'  => 4,
        ], $this->tz);
        $row = DB::fetch('SELECT urgency FROM entries WHERE id = ?', [$id]);
        $this->assertEquals(0, (int) $row['urgency']);
    }

    public function testUpdateEntryChangesUrgency(): void
    {
        $id = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        update_entry($id, $this->userId, [
            'occurred_at' => '2026-05-12T15:00:00',
            'stool_type'  => 4,
            'urgency'     => '1',
        ], $this->tz);
        $row = get_entry($id, $this->userId);
        $this->assertEquals(1, (int) $row['urgency']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/EntriesTest.php`
Expected: FAIL — urgency stays `0`/absent because `create_entry`/`update_entry` don't write it yet (test 1 and 3 fail; test 2 passes vacuously since column already defaults to 0).

- [ ] **Step 3: Implement**

In `lib/entries.php`:

```php
function create_entry(int $userId, array $data, string $timezone): ?int
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    $urgency = !empty($data['urgency']) ? 1 : 0;
    $stmt = DB::execute(
        'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note, urgency)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, $occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null, $urgency]
    );
    return $stmt->rowCount() > 0 ? (int) DB::lastInsertId() : null;
}

function update_entry(int $id, int $userId, array $data, string $timezone): ?bool
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    $urgency = !empty($data['urgency']) ? 1 : 0;
    try {
        $stmt = DB::execute(
            'UPDATE entries SET occurred_at=?, duration_seconds=?, stool_type=?, note=?, urgency=?
             WHERE id=? AND user_id=?',
            [$occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null, $urgency, $id, $userId]
        );
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
            return null;
        }
        throw $e;
    }
    return $stmt->rowCount() > 0;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/EntriesTest.php`
Expected: PASS (all EntriesTest tests)

- [ ] **Step 5: Commit**

```bash
git add lib/entries.php tests/EntriesTest.php
git commit -m "feat: persist urgency flag on entry create/update"
```

---

### Task 3: CSV export/import support

**Files:**
- Modify: `lib/entries.php` (`build_csv_export` lines 89-107, `normalize_native_row` lines 124-145, `normalize_poopify_row` lines 147-185, `import_csv` lines 187-256)
- Test: `tests/ExportTest.php`, `tests/ImportTest.php`

**Interfaces:**
- Consumes: `urgency` field from `create_entry`/`update_entry` (Task 2).
- Produces: CSV export header `occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note,urgency`; `normalize_native_row()` and `normalize_poopify_row()` return arrays that include `'urgency' => 0|1`, consumed by `import_csv()`'s two `INSERT OR IGNORE` calls.

- [ ] **Step 1: Write the failing tests**

Add to `tests/ExportTest.php`:

```php
    public function testExportCsvHeaderIncludesUrgency(): void
    {
        $csv   = build_csv_export([], $this->tz);
        $lines = explode("\n", trim($csv));
        $this->assertEquals(
            'occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note,urgency',
            $lines[0]
        );
    }

    public function testExportCsvIncludesUrgencyValue(): void
    {
        create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'stool_type'  => 4,
            'urgency'     => '1',
        ], $this->tz);
        $csv   = build_csv_export(get_all_entries($this->userId), $this->tz);
        $lines = explode("\n", trim($csv));
        $this->assertStringEndsWith(',1', $lines[1]);
    }
```

Add to `tests/ImportTest.php`:

```php
    public function testImportNativeReadsUrgencyWhenPresent(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note,urgency\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,300,4,Test note,1\n";
        import_csv($this->userId, $csv, $this->tz);
        $row = DB::fetch('SELECT urgency FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals(1, (int) $row['urgency']);
    }

    public function testImportNativeDefaultsUrgencyFalseWhenColumnAbsent(): void
    {
        // Older export format, no urgency column at all.
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,300,4,Test note\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $result['imported']);
        $row = DB::fetch('SELECT urgency FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals(0, (int) $row['urgency']);
    }

    public function testImportPoopifyAlwaysDefaultsUrgencyFalse(): void
    {
        $row = '"2026-05-27","10:00:00","Yes","","Like a smooth, soft sausage or snake","","","","","","","","","5","","","","","","","My note",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";
        import_csv($this->userId, $csv, $this->tz);
        $entry = DB::fetch('SELECT urgency FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals(0, (int) $entry['urgency']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/ExportTest.php tests/ImportTest.php`
Expected: FAIL — export header/value tests fail (no `urgency` column in output); `testImportNativeReadsUrgencyWhenPresent` fails (value not persisted); the other two pass vacuously (already defaults to 0).

- [ ] **Step 3: Implement**

In `lib/entries.php`, modify `build_csv_export`:

```php
function build_csv_export(array $entries, string $tz): string
{
    $buf = fopen('php://temp', 'w');
    fputcsv($buf, ['occurred_at_utc', 'occurred_at_local', 'duration_seconds', 'stool_type', 'note', 'urgency'], escape: '\\');
    foreach ($entries as $row) {
        $local = from_utc($row['occurred_at'], $tz)->format('Y-m-d H:i:s');
        fputcsv($buf, [
            $row['occurred_at'],
            $local,
            $row['duration_seconds'] ?? '',
            $row['stool_type'],
            $row['note'] ?? '',
            (int) ($row['urgency'] ?? 0),
        ], escape: '\\');
    }
    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);
    return $csv;
}
```

Modify `normalize_native_row`:

```php
function normalize_native_row(array $row, array $colIdx, int $rowNum): array|string
{
    $occurredAt  = $row[$colIdx['occurred_at_utc']] ?? '';
    $durationSec = ($row[$colIdx['duration_seconds']] ?? '') !== ''
        ? (int) $row[$colIdx['duration_seconds']] : null;
    $stoolRaw    = trim($row[$colIdx['stool_type']] ?? '');
    $note        = ($row[$colIdx['note']] ?? '') !== '' ? $row[$colIdx['note']] : null;
    $urgency     = isset($colIdx['urgency']) && ($row[$colIdx['urgency']] ?? '') !== ''
        ? (int) (bool) $row[$colIdx['urgency']] : 0;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $occurredAt)) {
        return "Row $rowNum: invalid occurred_at_utc \"$occurredAt\".";
    }
    if (!ctype_digit($stoolRaw) || (int) $stoolRaw < 1 || (int) $stoolRaw > 7) {
        return "Row $rowNum: stool_type \"$stoolRaw\" is not an integer in range 1–7.";
    }

    return [
        'occurred_at'      => $occurredAt,
        'stool_type'       => (int) $stoolRaw,
        'duration_seconds' => $durationSec,
        'note'             => $note,
        'urgency'          => $urgency,
    ];
}
```

Modify `normalize_poopify_row`'s return (add `'urgency' => 0` to the returned array):

```php
    return [
        'occurred_at'      => $occurredAt,
        'stool_type'       => $consistencyMap[$consistency],
        'duration_seconds' => $timeOnToilet !== '' ? (int) ($timeOnToilet * 60) : null,
        'note'             => $extraNotes !== '' ? $extraNotes : null,
        'urgency'          => 0,
    ];
```

Modify `import_csv`'s insert (inside the `while` loop):

```php
        $stmt = DB::execute(
            'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note, urgency)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $normalized['occurred_at'], $normalized['duration_seconds'],
             $normalized['stool_type'], $normalized['note'], $normalized['urgency']]
        );
```

Note: `$requiredCols` for the native format stays `['occurred_at_utc', 'duration_seconds', 'stool_type', 'note']` — `urgency` is deliberately not added, per the backward-compatibility requirement.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ExportTest.php tests/ImportTest.php`
Expected: PASS (all tests in both files)

- [ ] **Step 5: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (no regressions across DBTest, EntriesTest, ExportTest, ImportTest, AuthTest)

- [ ] **Step 6: Commit**

```bash
git add lib/entries.php tests/ExportTest.php tests/ImportTest.php
git commit -m "feat: include urgency in CSV export and import"
```

---

### Task 4: CSS toggle button styles

**Files:**
- Modify: `css/input.css` (color vars lines 3-11, add new class after `.btn-danger` block ~line 52)

**Interfaces:**
- Produces: CSS custom property `--color-orange: #E64A19`; class `.btn-urgency` (default state, green fill) and `.btn-urgency.active` (deep-orange fill), used by Task 5's markup.

This task has no PHPUnit coverage (pure CSS) — verified visually in Task 5's manual browser check.

- [ ] **Step 1: Add the color variable**

In `css/input.css`, modify the `:root` block:

```css
:root {
    --color-canvas:    #1a1a1a;
    --color-surface:   #2a2a2a;
    --color-border:    #3a3a3a;
    --color-green:     #00754A;
    --color-green-hd:  #006241;
    --color-orange:    #E64A19;
    --color-text:      rgba(255,255,255,0.87);
    --color-muted:     rgba(255,255,255,0.55);
    --color-red:       #c82014;
}
```

- [ ] **Step 2: Add the `.btn-urgency` class**

In `css/input.css`, after the `.btn-danger` block:

```css
.btn-urgency {
    background: var(--color-green);
    color: #fff;
    border: 1px solid var(--color-green);
    border-radius: 50px;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}
.btn-urgency:active { transform: scale(0.95); }
.btn-urgency.active {
    background: var(--color-orange);
    border-color: var(--color-orange);
}
```

- [ ] **Step 3: Rebuild compiled CSS**

Run: `npm run build:css`
Expected: `css/compiled.css` regenerates without errors; `grep -c "btn-urgency" css/compiled.css` returns a nonzero count.

- [ ] **Step 4: Commit**

```bash
git add css/input.css css/compiled.css
git commit -m "feat: add urgency toggle button styles"
```

---

### Task 5: Entry form toggle UI

**Files:**
- Modify: `views/partials/entry-form.php` (variable setup lines 1-32, button row lines 78-89, `resetForm()` lines 187-200)

**Interfaces:**
- Consumes: `.btn-urgency` / `.btn-urgency.active` classes from Task 4; `$entry['urgency']` from `get_entry()` (Task 2) when editing.
- Produces: form field `name="urgency"` (hidden input, value `0`/`1`) submitted alongside `note`, `stool_type`, etc. to the existing `create_entry`/`update_entry` handlers from Task 2.

No PHPUnit coverage for this task (form markup + JS) — verified manually per Step 4 below, consistent with how the existing timer button isn't unit tested.

- [ ] **Step 1: Add urgency variable and hidden input + toggle button**

In `views/partials/entry-form.php`, add near the other `$entry`-derived variables (after line 21, `$noteVal = $entry['note'] ?? '';`):

```php
    $urgencyVal   = !empty($entry['urgency']);
```

And in the `else` branch (after line 29, `$noteVal = '';`):

```php
    $urgencyVal   = false;
```

Replace the button row (lines 78-89):

```php
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn-primary">Save</button>
                <?php if ($isEdit): ?>
                <button type="button" class="btn-outline"
                    hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/new"
                    hx-target="#entry-form-wrap"
                    hx-swap="outerHTML">Cancel</button>
                <?php else: ?>
                <button type="button" class="btn-outline" onclick="resetForm()">Reset</button>
                <button type="button" id="timer-btn" class="btn-outline" onclick="toggleTimer()">▶</button>
                <?php endif; ?>
            </div>
            <input type="hidden" name="urgency" id="urgency-input" value="<?= $urgencyVal ? '1' : '0' ?>">
            <button type="button" id="urgency-btn"
                class="btn-urgency<?= $urgencyVal ? ' active' : '' ?>"
                onclick="toggleUrgency()">Urgency <?= $urgencyVal ? '🫪' : '😌' ?></button>
        </div>
```

- [ ] **Step 2: Add the toggle script and wire it into `resetForm()`**

In the `<script>` block, add inside the existing IIFE (after the `window.resetForm` function definition ends, before the closing `}());`):

```javascript
    window.toggleUrgency = function () {
        var input = document.getElementById('urgency-input');
        var btn = document.getElementById('urgency-btn');
        var isActive = input.value === '1';
        input.value = isActive ? '0' : '1';
        btn.classList.toggle('active', !isActive);
        btn.textContent = isActive ? 'Urgency 😌' : 'Urgency 🫪';
    };
```

Modify `window.resetForm` to also reset urgency (replace the whole function with this version):

```javascript
    window.resetForm = function () {
        if (isTimerRunning()) captureAndStopTimer();
        var now = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var local = now.getFullYear() + '-' +
            pad(now.getMonth() + 1) + '-' +
            pad(now.getDate()) + 'T' +
            pad(now.getHours()) + ':' +
            pad(now.getMinutes());
        document.querySelector('[name="occurred_at"]').value = local;
        document.querySelector('[name="duration"]').value = '05:00';
        document.querySelector('[name="note"]').value = '';
        selectType(4);
        var urgencyInput = document.getElementById('urgency-input');
        var urgencyBtn = document.getElementById('urgency-btn');
        if (urgencyInput && urgencyInput.value === '1') {
            urgencyInput.value = '0';
            urgencyBtn.classList.remove('active');
            urgencyBtn.textContent = 'Urgency 😌';
        }
    };
```

- [ ] **Step 3: PHP syntax check**

Run: `php -l views/partials/entry-form.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual browser verification**

Start the dev server per the project's usual run method (check for a `pitchfork.toml`/README run command, or `php -S localhost:8000 -t .`), then in a browser:
1. Load the new-entry form. Confirm the `Urgency 😌` button renders green, right-justified on the same line as `Save`/`Reset`/the timer button.
2. Click it — confirm it turns deep orange and reads `Urgency 🫪`.
3. Click `Reset` — confirm it reverts to green `Urgency 😌`.
4. Toggle it on, submit the form, confirm the entry saves.
5. Click `Edit` on that entry — confirm the toggle pre-loads as orange/`🫪`.
6. Toggle it off, save — confirm it persists as off.

- [ ] **Step 5: Commit**

```bash
git add views/partials/entry-form.php
git commit -m "feat: add urgency toggle to entry form"
```

---

### Task 6: Wire urgency through router POST handlers

> **Plan amendment (post-Task-5):** The original plan omitted `index.php` (the router) entirely — Tasks 2's `create_entry`/`update_entry` accept `$data['urgency']`, and Task 5 submits `name="urgency"` in the form, but nothing in the original plan copied `$_POST['urgency']` into the data array the router builds. Task 5's implementer caught this: the toggle currently has no effect on save. This task closes that gap.

**Files:**
- Modify: `index.php` (POST `/entries` handler ~lines 101-106, POST `/entries/:id` handler ~lines 248-253)
- Test: `tests/integration/test-urgency-toggle.sh` (new)

**Interfaces:**
- Consumes: `create_entry(int $userId, array $data, string $timezone): ?int` / `update_entry(int $id, int $userId, array $data, string $timezone): ?bool` from Task 2 — both already accept `$data['urgency']` and cast truthy values to `1`.
- Consumes: form field `name="urgency"` (hidden input, value `"0"`/`"1"`) from Task 5's `views/partials/entry-form.php`.
- Consumes: the project's `rodney`-based browser integration test harness (`tests/integration/run.php`, which globs `tests/integration/test-*.sh` and runs each against a live `php -S` server with a fresh SQLite DB — see `tests/integration/test-calendar-updates-on-entry.sh` for the reference pattern: `rodney start --local`, `rodney open`, `rodney input`, `rodney click`, `rodney js`, `rodney assert`).

- [ ] **Step 1: Write the failing integration test**

Create `tests/integration/test-urgency-toggle.sh`:

```bash
#!/usr/bin/env bash
# Tests: urgency toggle on the entry form persists through create and edit
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-urgency-toggle ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

rodney visible '#entry-form-wrap'
echo "PASS: logged in (home page loaded)"

# Default state: green, off
rodney assert "document.getElementById('urgency-input').value" "0"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "false"
echo "PASS: urgency toggle defaults to off"

# Toggle on, then submit a new entry
rodney click '#urgency-btn'
rodney assert "document.getElementById('urgency-input').value" "1"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "true"
echo "PASS: toggle switches to on (active class, hidden input = 1)"

NOW=$(date '+%Y-%m-%dT%H:%M')
rodney js "document.querySelector('[name=\"occurred_at\"]').value = '$NOW'"
rodney js "document.getElementById('stool-type-input').value = '4'"
rodney click 'button.btn-primary'
rodney sleep 0.5
echo "PASS: submitted entry with urgency on"

# Edit the entry back open and confirm the toggle pre-loads as on
rodney click '.btn-outline'
rodney sleep 0.3
rodney assert "document.getElementById('urgency-input').value" "1"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "true"
echo "PASS: edit form pre-loads urgency as on"

echo "ALL PASS"
```

Make it executable: `chmod +x tests/integration/test-urgency-toggle.sh`

- [ ] **Step 2: Run the integration suite to verify the new test fails**

Run: `php tests/integration/run.php`
Expected: `test-urgency-toggle` fails at the "PASS: submitted entry with urgency on" step or later — the router doesn't persist `urgency`, so re-opening the edit form will show the toggle back at `0`/off. (All other integration tests continue to pass.)

- [ ] **Step 3: Implement**

In `index.php`, the POST `/entries` handler — add `'urgency'` to the `create_entry()` call's data array:

```php
    $newId = create_entry($userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
        'urgency'     => $_POST['urgency'] ?? '0',
    ], $tz);
```

In `index.php`, the POST `/entries/:id` handler — add `'urgency'` to the `update_entry()` call's data array:

```php
    $updateResult = update_entry((int) $id, $userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
        'urgency'     => $_POST['urgency'] ?? '0',
    ], $tz);
```

- [ ] **Step 4: Run the integration suite to verify it passes**

Run: `php tests/integration/run.php`
Expected: `All N integration test(s) passed.` (existing tests plus `test-urgency-toggle`, no regressions)

- [ ] **Step 5: Run the full PHPUnit suite as a regression check**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions (this task doesn't touch `lib/`, but confirms nothing else broke)

- [ ] **Step 6: Commit**

```bash
git add index.php tests/integration/test-urgency-toggle.sh
git commit -m "fix: persist urgency flag from entry form submissions"
```

---

### Task 7: Entries list indicator

**Files:**
- Modify: `views/partials/event-rows.php` (lines 9-33)

**Interfaces:**
- Consumes: `$row['urgency']` from `get_entries()`/`get_entries_for_month()` (Task 2, unchanged `SELECT *`).

No PHPUnit coverage (this file has no existing test coverage; it's rendered inline in `views/entries.php` or similar and exercised via the manual check below).

- [ ] **Step 1: Add the urgency badge**

In `views/partials/event-rows.php`, modify the loop variable setup (after line 15, `$note = ...`):

```php
    $urgent   = !empty($row['urgency']);
```

Modify the duration/note `<td>` (lines 28-33):

```php
    <td style="padding:10px 4px;font-size:12px;color:var(--color-muted);">
        <?= htmlspecialchars($duration) ?><?php if ($urgent): ?> <span title="Urgent">🫪</span><?php endif; ?>
        <?php if ($note): ?>
        <div style="color:var(--color-muted);"><?= htmlspecialchars($note) ?></div>
        <?php endif; ?>
    </td>
```

- [ ] **Step 2: PHP syntax check**

Run: `php -l views/partials/event-rows.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual browser verification**

With the dev server running: create one entry with urgency on and one with it off. Confirm the entries list shows the 🫪 badge next to the duration only for the urgent entry.

- [ ] **Step 4: Commit**

```bash
git add views/partials/event-rows.php
git commit -m "feat: show urgency badge in entries list"
```

---

### Task 8: Full regression pass

**Files:** None (verification only)

- [ ] **Step 1: Run the full PHP test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, 0 failures, 0 errors.

- [ ] **Step 2: Run PHP lint across all tracked files**

Run: `find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -exec php -l {} \;`
Expected: `No syntax errors detected` for every file, no output containing `Errors parsing`.

- [ ] **Step 3: Confirm compiled CSS is committed and up to date**

Run: `npm run build:css && git status --porcelain css/compiled.css`
Expected: empty output (no uncommitted diff) — if there's a diff, stage and commit it.

- [ ] **Step 4: Run the full browser integration suite**

Run: `php tests/integration/run.php`
Expected: `All N integration test(s) passed.` — includes `test-urgency-toggle.sh` from Task 6 plus all pre-existing integration tests (timer, offline queue, calendar), confirming no regressions in the interactive JS this feature touched (the button-row layout change in Task 5, the toggle script).
