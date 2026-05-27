# CSV Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/import` page that accepts a gistats-native or Poopify CSV export and inserts new entries, skipping duplicates gracefully at both the import layer and the normal entry-create/update flow.

**Architecture:** A unique index on `(user_id, occurred_at)` enforces deduplication at the DB level. `import_csv()` in `lib/entries.php` auto-detects format, validates headers upfront, and delegates row parsing to `normalize_native_row()` / `normalize_poopify_row()` — keeping each normalizer independently testable. `create_entry` and `update_entry` use `INSERT OR IGNORE` / try-catch to return `null` on conflict so route handlers can show a friendly flash error instead of a 500.

**Tech Stack:** PHP 8.x, SQLite via PDO, PHPUnit for tests, HTMX not used on the import page (standard form POST).

---

### Task 1: Schema — unique index + fix existing timestamp collision in tests

**Files:**
- Modify: `lib/db.php`
- Modify: `tests/EntriesTest.php`
- Create: `tests/ImportTest.php`

Adding a unique index on `(user_id, occurred_at)` will immediately break
`testGetEntriesPagination` in `EntriesTest`, which creates 5 entries all at
`2026-05-12T14:00:00` for the same user. That step is part of this task.

- [ ] **Step 1: Create `tests/ImportTest.php` with a failing schema test**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/entries.php';

class ImportTest extends TestCase
{
    private string $dbPath;
    private int $userId = 1;
    private string $tz = 'America/Chicago';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_import_test_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('x', PASSWORD_BCRYPT)]
        );
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testDuplicateOccurredAtRejected(): void
    {
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type) VALUES (?, ?, ?)',
            [$this->userId, '2026-05-27T12:00:00Z', 4]
        );
        $this->expectException(\PDOException::class);
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type) VALUES (?, ?, ?)',
            [$this->userId, '2026-05-27T12:00:00Z', 4]
        );
    }
}
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: FAIL — no exception is thrown (unique constraint not yet present).

- [ ] **Step 3: Add the unique index to `DB::createSchema()` in `lib/db.php`**

Append one line inside the `exec()` string, after the existing `CREATE INDEX` statement:

```php
            CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_occurred_unique
                ON entries(user_id, occurred_at);
```

The full `exec()` call should now end with:

```php
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
                created_at       TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
            CREATE INDEX IF NOT EXISTS idx_entries_user_occurred
                ON entries(user_id, occurred_at DESC);
            CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_occurred_unique
                ON entries(user_id, occurred_at);
        ');
```

- [ ] **Step 4: Run `tests/ImportTest.php` — confirm it PASSES**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: OK (1 test, 1 assertion).

- [ ] **Step 5: Run the full suite — identify the broken pagination test**

```bash
./vendor/bin/phpunit
```

Expected: `testGetEntriesPagination` in `EntriesTest` FAILS with a unique constraint
error, because all 5 entries share the same `occurred_at`.

- [ ] **Step 6: Fix `testGetEntriesPagination` in `tests/EntriesTest.php`**

Find the test (it creates 5 entries in a loop at `2026-05-12T14:00:00`). Give
each entry a distinct timestamp by using the loop counter in the minutes field:

```php
    public function testGetEntriesPagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            create_entry($this->userId, [
                'occurred_at' => sprintf('2026-05-12T%02d:00:00', 10 + $i),
                'stool_type'  => 4,
            ], $this->tz);
        }
        $page1 = get_entries($this->userId, 0, 3);
        $page2 = get_entries($this->userId, 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }
```

- [ ] **Step 7: Run the full suite — confirm everything passes**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add lib/db.php tests/ImportTest.php tests/EntriesTest.php
git commit -m "feat: add unique index on (user_id, occurred_at) to prevent duplicate entries"
```

---

### Task 2: `create_entry` and `update_entry` — duplicate handling

**Files:**
- Modify: `lib/entries.php`
- Modify: `index.php`
- Modify: `tests/EntriesTest.php`

With the unique index in place, a duplicate timestamp submitted via the entry
form would produce an unhandled `PDOException`. This task makes both functions
return `null` on conflict and updates the route handlers to show a friendly flash.

- [ ] **Step 1: Add failing tests to `tests/EntriesTest.php`**

Append these two test methods to `EntriesTest`:

```php
    public function testCreateEntryReturnsNullOnDuplicateTimestamp(): void
    {
        create_entry($this->userId, [
            'occurred_at' => '2026-05-27T12:00:00',
            'stool_type'  => 4,
        ], $this->tz);
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-27T12:00:00',
            'stool_type'  => 4,
        ], $this->tz);
        $this->assertNull($id);
    }

    public function testUpdateEntryReturnsNullOnConflictingTimestamp(): void
    {
        $id1 = create_entry($this->userId, [
            'occurred_at' => '2026-05-27T12:00:00',
            'stool_type'  => 4,
        ], $this->tz);
        $id2 = create_entry($this->userId, [
            'occurred_at' => '2026-05-27T13:00:00',
            'stool_type'  => 4,
        ], $this->tz);
        $ok = update_entry($id2, $this->userId, [
            'occurred_at' => '2026-05-27T12:00:00', // conflicts with id1
            'stool_type'  => 4,
        ], $this->tz);
        $this->assertNull($ok);
        // original entry is unchanged
        $row = get_entry($id2, $this->userId);
        $this->assertEquals('2026-05-27T18:00:00Z', $row['occurred_at']);
    }
```

- [ ] **Step 2: Run the new tests — confirm they FAIL**

```bash
./vendor/bin/phpunit tests/EntriesTest.php --filter "NullOn"
```

Expected: FAIL — `create_entry` throws `PDOException`; `update_entry` also throws.

- [ ] **Step 3: Update `create_entry` in `lib/entries.php`**

Replace the current `create_entry` function with:

```php
function create_entry(int $userId, array $data, string $timezone): ?int
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    $stmt = DB::execute(
        'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
         VALUES (?, ?, ?, ?, ?)',
        [$userId, $occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null]
    );
    return $stmt->rowCount() > 0 ? (int) DB::lastInsertId() : null;
}
```

- [ ] **Step 4: Update `update_entry` in `lib/entries.php`**

Replace the current `update_entry` function with:

```php
function update_entry(int $id, int $userId, array $data, string $timezone): ?bool
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    try {
        $stmt = DB::execute(
            'UPDATE entries SET occurred_at=?, duration_seconds=?, stool_type=?, note=?
             WHERE id=? AND user_id=?',
            [$occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null, $id, $userId]
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

- [ ] **Step 5: Run the new tests — confirm they PASS**

```bash
./vendor/bin/phpunit tests/EntriesTest.php --filter "NullOn"
```

Expected: OK (2 tests passing).

- [ ] **Step 6: Run the full suite — confirm existing tests still pass**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass. (`?bool` is backward-compatible; existing `assertTrue`
and `assertFalse` calls on the non-null return values still work.)

- [ ] **Step 7: Update the `POST /entries` route handler in `index.php`**

Find the block that calls `create_entry` in the `POST /entries` handler. Replace:

```php
    create_entry($userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
    ], $tz);

    // Return blank form + flash message for HTMX; redirect for non-HTMX
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
```

With:

```php
    $newId = create_entry($userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
    ], $tz);

    if ($newId === null && !empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = null;
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'error'; $flash_message = 'An entry already exists at this time.'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        return;
    }

    // Return blank form + flash message for HTMX; redirect for non-HTMX
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
```

- [ ] **Step 8: Update the `POST /entries/:id` route handler in `index.php`**

Find the block that calls `update_entry` in the `POST /entries/:id` handler.
Replace:

```php
    update_entry((int) $id, $userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
    ], $tz);

    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
```

With:

```php
    $updateResult = update_entry((int) $id, $userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
    ], $tz);

    if ($updateResult === null && !empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = array_merge($row, ['is_edit' => true]);
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'error'; $flash_message = 'An entry already exists at this time.'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        return;
    }

    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
```

- [ ] **Step 9: Run the full suite**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add lib/entries.php index.php tests/EntriesTest.php
git commit -m "feat: handle duplicate timestamp in create_entry and update_entry with friendly error"
```

---

### Task 3: `normalize_native_row` + `import_csv` native format

**Files:**
- Modify: `lib/entries.php`
- Modify: `tests/ImportTest.php`

- [ ] **Step 1: Add failing tests to `tests/ImportTest.php`**

Append these test methods to `ImportTest`:

```php
    public function testImportUnrecognizedFormatReturnsError(): void
    {
        $csv    = "foo,bar,baz\n1,2,3\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Unrecognized', $result['errors'][0]);
    }

    public function testImportNativeMissingRequiredColumnReturnsError(): void
    {
        // stool_type column is absent
        $csv    = "occurred_at_utc,occurred_at_local,duration_seconds,note\n"
                . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,,\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertStringContainsString('stool_type', $result['errors'][0]);
    }

    public function testImportNativeHappyPath(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,300,4,Test note\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped_duplicates']);
        $this->assertEmpty($result['errors']);

        $row = DB::fetch('SELECT * FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals('2026-05-27T12:00:00Z', $row['occurred_at']);
        $this->assertEquals(300, (int) $row['duration_seconds']);
        $this->assertEquals(4, (int) $row['stool_type']);
        $this->assertEquals('Test note', $row['note']);
    }

    public function testImportNativeNullNote(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,,4,\n";
        import_csv($this->userId, $csv, $this->tz);
        $row = DB::fetch('SELECT note FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertNull($row['note']);
    }

    public function testImportNativeSkipsDuplicate(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,,4,\n";
        import_csv($this->userId, $csv, $this->tz);
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['skipped_duplicates']);
    }

    public function testImportNativeInvalidStoolTypeSkipsRow(): void
    {
        $cases = [
            ['stool_type' => '8',   'desc' => 'above range'],
            ['stool_type' => '0',   'desc' => 'zero'],
            ['stool_type' => '-1',  'desc' => 'negative'],
            ['stool_type' => 'abc', 'desc' => 'non-numeric'],
        ];
        foreach ($cases as $case) {
            $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
                 . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,," . $case['stool_type'] . ",\n";
            $result = import_csv($this->userId, $csv, $this->tz);
            $this->assertEquals(0, $result['imported'], 'Should not import for: ' . $case['desc']);
            $this->assertNotEmpty($result['errors'], 'Should report error for: ' . $case['desc']);
        }
    }

    public function testImportNativeHeaderOnlyNoDataRows(): void
    {
        $csv    = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(0, $result['skipped_duplicates']);
        $this->assertEmpty($result['errors']);
    }

    public function testImportNativeRoundTrip(): void
    {
        create_entry($this->userId, [
            'occurred_at' => '2026-05-27T12:00:00',
            'duration'    => '05:00',
            'stool_type'  => 4,
            'note'        => 'hello',
        ], $this->tz);
        $csv    = build_csv_export(get_all_entries($this->userId), $this->tz);
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['skipped_duplicates']);
        $this->assertEmpty($result['errors']);
    }
```

- [ ] **Step 2: Run the tests — confirm they FAIL**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: FAIL — `import_csv` and `normalize_native_row` are not defined.

- [ ] **Step 3: Add `normalize_native_row` and `import_csv` to `lib/entries.php`**

Append both functions to the end of `lib/entries.php`:

```php
function normalize_native_row(array $row, array $colIdx, int $rowNum): array|string
{
    $occurredAt  = $row[$colIdx['occurred_at_utc']] ?? '';
    $durationSec = ($row[$colIdx['duration_seconds']] ?? '') !== ''
        ? (int) $row[$colIdx['duration_seconds']] : null;
    $stoolRaw    = trim($row[$colIdx['stool_type']] ?? '');
    $note        = ($row[$colIdx['note']] ?? '') !== '' ? $row[$colIdx['note']] : null;

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
    ];
}

function import_csv(int $userId, string $csvContent, string $timezone): array
{
    $result = ['imported' => 0, 'skipped_duplicates' => 0, 'errors' => []];

    $buf = fopen('php://temp', 'r+');
    fwrite($buf, $csvContent);
    rewind($buf);

    $header = fgetcsv($buf, escape: '\\');
    if ($header === false) {
        $result['errors'][] = 'Empty file.';
        fclose($buf);
        return $result;
    }

    $firstCol = $header[0];
    if ($firstCol === 'occurred_at_utc') {
        $format       = 'native';
        $requiredCols = ['occurred_at_utc', 'duration_seconds', 'stool_type', 'note'];
    } elseif ($firstCol === 'Date') {
        $format       = 'poopify';
        $requiredCols = ['Date', 'Time', 'There was stool', 'Consistency', 'Time on toilet', 'Extra notes'];
    } else {
        $result['errors'][] = "Unrecognized CSV format (first column: \"$firstCol\").";
        fclose($buf);
        return $result;
    }

    $missing = array_diff($requiredCols, $header);
    if ($missing) {
        $result['errors'][] = 'Missing required columns: ' . implode(', ', array_values($missing)) . '.';
        fclose($buf);
        return $result;
    }

    $colIdx = array_flip($header);
    $rowNum = 1;

    while (($row = fgetcsv($buf, escape: '\\')) !== false) {
        $rowNum++;

        $normalized = $format === 'native'
            ? normalize_native_row($row, $colIdx, $rowNum)
            : normalize_poopify_row($row, $colIdx, $rowNum, $timezone);

        if ($normalized === null) {
            continue;
        }
        if (is_string($normalized)) {
            $result['errors'][] = $normalized;
            continue;
        }

        $stmt = DB::execute(
            'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $normalized['occurred_at'], $normalized['duration_seconds'],
             $normalized['stool_type'], $normalized['note']]
        );

        if ($stmt->rowCount() === 0) {
            $result['skipped_duplicates']++;
        } else {
            $result['imported']++;
        }
    }

    fclose($buf);
    return $result;
}
```

Note: `normalize_poopify_row` is called but not yet defined; PHP will throw a
fatal error only when a Poopify file is actually processed, which no test in
this task does.

- [ ] **Step 4: Run the native tests — confirm they PASS**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: all ImportTest tests pass.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add lib/entries.php tests/ImportTest.php
git commit -m "feat: add normalize_native_row and import_csv with native format support"
```

---

### Task 4: `normalize_poopify_row` — Poopify format

**Files:**
- Modify: `lib/entries.php`
- Modify: `tests/ImportTest.php`

**Context:** The Poopify CSV header has 22 quoted columns. `fgetcsv` strips
quotes, so column names are plain strings. `There was stool = "No"` rows are
silently skipped — they do not appear in `errors[]`. America/Chicago in May
is CDT (UTC−5), so `10:00:00` local = `15:00:00Z`.

- [ ] **Step 1: Add failing Poopify tests to `tests/ImportTest.php`**

Add the private helper and these test methods to `ImportTest`:

```php
    private string $poopifyHeader = '"Date","Time","There was stool","Color","Consistency","Secondary Consistency","Smell","Volume","Girth","Pain","Stickiness","Hygiene method","Symptoms/Sensations","Time on toilet","Presence of blood","Does it float?","Presence of pieces of food","Excessive flatulence","Presence of mucus","Medication related to this activity","Extra notes","Food information"';

    public function testImportPoopifyMissingRequiredColumnReturnsError(): void
    {
        // Header missing "Extra notes" column
        $header = '"Date","Time","There was stool","Color","Consistency","Secondary Consistency","Smell","Volume","Girth","Pain","Stickiness","Hygiene method","Symptoms/Sensations","Time on toilet","Presence of blood","Does it float?","Presence of pieces of food","Excessive flatulence","Presence of mucus","Medication related to this activity","Food information"';
        $csv    = $header . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertStringContainsString('Extra notes', $result['errors'][0]);
    }

    public function testImportPoopifyHappyPath(): void
    {
        $row = '"2026-05-27","10:00:00","Yes","","Like a smooth, soft sausage or snake","","","","","","","","","5","","","","","","","My note",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";

        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $result['imported']);
        $this->assertEmpty($result['errors']);

        $entry = DB::fetch('SELECT * FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals(4, (int) $entry['stool_type']);
        $this->assertEquals(300, (int) $entry['duration_seconds']); // 5 min × 60
        $this->assertEquals('My note', $entry['note']);
        // America/Chicago CDT = UTC−5; 10:00 local → 15:00 UTC
        $this->assertEquals('2026-05-27T15:00:00Z', $entry['occurred_at']);
    }

    public function testImportPoopifyAllConsistencyTypes(): void
    {
        $consistencies = [
            'Separated hard lumps'                 => 1,
            'Lumpy and sausage like'               => 2,
            'Sausage shaped with cracks'           => 3,
            'Like a smooth, soft sausage or snake' => 4,
            'Soft blobs, with clear-cut edges'     => 5,
            'Mushy consistency with ragged edges'  => 6,
            'Liquid, with no solid pieces'         => 7,
        ];
        $hour = 10;
        foreach ($consistencies as $label => $expectedType) {
            $time       = sprintf('%02d:00:00', $hour++);
            $utcTime    = sprintf('%02d:00:00Z', $hour - 1 + 5); // CDT+5 = UTC
            $occurredAt = '2026-05-27T' . $utcTime;
            $row        = "\"2026-05-27\",\"$time\",\"Yes\",\"\",\"$label\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"";
            $csv        = $this->poopifyHeader . "\n" . $row . "\n";

            $result = import_csv($this->userId, $csv, $this->tz);
            $this->assertEquals(1, $result['imported'], "Failed for: $label");

            $entry = DB::fetch(
                'SELECT stool_type FROM entries WHERE user_id = ? AND occurred_at = ?',
                [$this->userId, $occurredAt]
            );
            $this->assertEquals($expectedType, (int) $entry['stool_type'], "Wrong type for: $label");
        }
    }

    public function testImportPoopifyThereWasStoolNotYesSilentlySkipped(): void
    {
        $row    = '"2026-05-27","10:00:00","No","","Like a smooth, soft sausage or snake","","","","","","","","","1","","","","","","","",""';
        $csv    = $this->poopifyHeader . "\n" . $row . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertEmpty($result['errors']); // silent, no error reported
    }

    public function testImportPoopifyUnrecognizedConsistencySkipsRow(): void
    {
        $row    = '"2026-05-27","10:00:00","Yes","","Unknown texture","","","","","","","","","1","","","","","","","",""';
        $csv    = $this->poopifyHeader . "\n" . $row . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Unknown texture', $result['errors'][0]);
    }

    public function testImportPoopifyTwiceProducesNoDuplicates(): void
    {
        $row    = '"2026-05-27","10:00:00","Yes","","Like a smooth, soft sausage or snake","","","","","","","","","1","","","","","","","",""';
        $csv    = $this->poopifyHeader . "\n" . $row . "\n";
        $first  = import_csv($this->userId, $csv, $this->tz);
        $second = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $first['imported']);
        $this->assertEquals(0, $second['imported']);
        $this->assertEquals(1, $second['skipped_duplicates']);
        $this->assertEquals(1, (int) DB::fetch('SELECT COUNT(*) as n FROM entries', [])['n']);
    }
```

- [ ] **Step 2: Run the Poopify tests — confirm they FAIL**

```bash
./vendor/bin/phpunit tests/ImportTest.php --filter Poopify
```

Expected: FAIL — `normalize_poopify_row` is undefined (fatal error).

- [ ] **Step 3: Add `normalize_poopify_row` to `lib/entries.php`**

Insert this function immediately before `import_csv` in `lib/entries.php`:

```php
function normalize_poopify_row(array $row, array $colIdx, int $rowNum, string $timezone): array|string|null
{
    static $consistencyMap = [
        'Separated hard lumps'                 => 1,
        'Lumpy and sausage like'               => 2,
        'Sausage shaped with cracks'           => 3,
        'Like a smooth, soft sausage or snake' => 4,
        'Soft blobs, with clear-cut edges'     => 5,
        'Mushy consistency with ragged edges'  => 6,
        'Liquid, with no solid pieces'         => 7,
    ];

    if (($row[$colIdx['There was stool']] ?? '') !== 'Yes') {
        return null;
    }

    $consistency = $row[$colIdx['Consistency']] ?? '';
    if (!array_key_exists($consistency, $consistencyMap)) {
        return "Row $rowNum: unrecognized consistency \"$consistency\".";
    }

    $date = $row[$colIdx['Date']] ?? '';
    $time = $row[$colIdx['Time']] ?? '';
    try {
        $occurredAt = to_utc("$date $time", $timezone);
    } catch (\Exception $e) {
        return "Row $rowNum: invalid date/time \"$date $time\".";
    }

    $timeOnToilet = $row[$colIdx['Time on toilet']] ?? '';
    $extraNotes   = $row[$colIdx['Extra notes']] ?? '';

    return [
        'occurred_at'      => $occurredAt,
        'stool_type'       => $consistencyMap[$consistency],
        'duration_seconds' => $timeOnToilet !== '' ? (int) $timeOnToilet * 60 : null,
        'note'             => $extraNotes !== '' ? $extraNotes : null,
    ];
}
```

- [ ] **Step 4: Run the Poopify tests — confirm they PASS**

```bash
./vendor/bin/phpunit tests/ImportTest.php --filter Poopify
```

Expected: OK (all Poopify tests passing).

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add lib/entries.php tests/ImportTest.php
git commit -m "feat: add normalize_poopify_row and Poopify CSV format support in import_csv"
```

---

### Task 5: HTTP routes, import view, and nav link

**Files:**
- Modify: `index.php`
- Create: `views/import.php`
- Modify: `views/layout.php`

There are no unit tests for route handlers in this codebase; verify manually.

- [ ] **Step 1: Add `GET /import` and `POST /import` routes to `index.php`**

Add the following two routes after the existing `$router->get('/export', ...)`
block (around line 323):

```php
$router->get('/import', function () use ($config) {
    require_auth();
    render('import');
});

$router->post('/import', function () use ($config) {
    require_auth();
    require_csrf();
    $tz = $config['timezone'];

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        render('import', ['error' => 'No file uploaded or upload error.']);
        return;
    }

    $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($csvContent === false || trim($csvContent) === '') {
        render('import', ['error' => 'The uploaded file is empty.']);
        return;
    }

    $result = import_csv(current_user_id(), $csvContent, $tz);
    render('import', ['result' => $result]);
});
```

- [ ] **Step 2: Create `views/import.php`**

```php
<?php
// Variables available: $result (array|null), $error (string|null)
$result = $result ?? null;
$error  = $error  ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Import CSV</h2>

<?php if ($result !== null): ?>
<div style="margin-bottom:20px;padding:12px 16px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:8px;">
    <p style="margin:0 0 4px;">
        Imported <?= $result['imported'] ?> <?= $result['imported'] === 1 ? 'entry' : 'entries' ?>.
        <?php if ($result['skipped_duplicates'] > 0): ?>
        Skipped <?= $result['skipped_duplicates'] ?> duplicate<?= $result['skipped_duplicates'] === 1 ? '' : 's' ?>.
        <?php endif; ?>
    </p>
    <?php if ($result['errors']): ?>
    <ul style="margin:8px 0 0;padding-left:20px;font-size:13px;color:var(--color-muted);">
        <?php foreach ($result['errors'] as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php elseif ($error !== null): ?>
<div style="margin-bottom:20px;padding:12px 16px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:8px;color:var(--color-muted);">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/import"
      enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div style="margin-bottom:16px;">
        <label style="display:block;font-size:13px;color:var(--color-muted);margin-bottom:6px;">CSV file</label>
        <input type="file" name="csv_file" accept=".csv,text/csv" required
               style="display:block;width:100%;font-size:14px;">
    </div>
    <button type="submit" class="btn-primary">Import</button>
</form>

<p style="margin-top:20px;font-size:13px;color:var(--color-muted);">
    Supported formats: gistats export CSV and Poopify export CSV.
    Duplicate entries (same timestamp) are automatically skipped.
</p>
```

- [ ] **Step 3: Add "Import CSV" to the nav drawer in `views/layout.php`**

Find the existing Export CSV list item (around line 38):

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
```

Add the Import CSV link immediately after it:

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/import" style="color:var(--color-text);text-decoration:none;font-size:15px;">Import CSV</a></li>
```

- [ ] **Step 4: Smoke test the feature manually**

Start the dev server:

```bash
php -S localhost:8080 server.php
```

Open `http://localhost:8080` and verify:

1. Nav drawer shows "Import CSV" directly below "Export CSV".
2. Navigating to `/import` renders the file upload form with no errors.
3. Uploading `tmp/poopify_example.csv` shows the imported entry count with zero per-row errors.
4. Uploading the same file again shows 0 imported and all entries skipped as duplicates.
5. Use `/export` to download a CSV, then import it — all entries skipped as duplicates, none re-inserted.
6. Uploading an empty file shows the "uploaded file is empty" error message.
7. On the entry form, attempt to save two entries with the same date and time — the second save shows the flash error "An entry already exists at this time."

- [ ] **Step 5: Commit**

```bash
git add index.php views/import.php views/layout.php
git commit -m "feat: add CSV import page with nav drawer link"
```
