# CSV Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/import` page that accepts a gistats-native or Poopify CSV export and inserts new entries, skipping duplicates.

**Architecture:** A single `import_csv()` function in `lib/entries.php` auto-detects the CSV format from the header row, normalizes each row to the internal data model, and inserts via `INSERT OR IGNORE` backed by a new unique index on `(user_id, occurred_at)`. Two routes (`GET /import`, `POST /import`) and a new view handle the UI; a nav drawer link surfaces the feature.

**Tech Stack:** PHP 8.x, SQLite via PDO, PHPUnit for tests, HTMX not used on this page (standard form POST).

---

### Task 1: Schema — add unique index on (user_id, occurred_at)

**Files:**
- Modify: `lib/db.php`
- Create: `tests/ImportTest.php`

- [ ] **Step 1: Create `tests/ImportTest.php` with boilerplate and a failing schema test**

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

Expected: FAIL — `testDuplicateOccurredAtRejected` passes without throwing (no unique constraint yet).

- [ ] **Step 3: Add the unique index to `DB::createSchema()`**

In `lib/db.php`, append this line inside the `exec()` call in `createSchema()`, after the existing `CREATE INDEX` statement:

```php
            CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_occurred_unique
                ON entries(user_id, occurred_at);
```

The full `createSchema()` exec block should end with:

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

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: OK (1 test, 1 assertion)

- [ ] **Step 5: Run the full test suite — confirm nothing broken**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass. (The unique index uses `IF NOT EXISTS` and applies only to new schemas created in tests — existing tests are unaffected.)

- [ ] **Step 6: Commit**

```bash
git add lib/db.php tests/ImportTest.php
git commit -m "feat: add unique index on (user_id, occurred_at) to prevent duplicate entries"
```

---

### Task 2: `import_csv` — format detection + native format

**Files:**
- Modify: `lib/entries.php`
- Modify: `tests/ImportTest.php`

- [ ] **Step 1: Add failing tests to `tests/ImportTest.php`**

Append these four test methods to the `ImportTest` class:

```php
    public function testImportUnrecognizedFormatReturnsError(): void
    {
        $csv = "foo,bar,baz\n1,2,3\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Unrecognized', $result['errors'][0]);
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

    public function testImportNativeSkipsDuplicate(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,300,4,\n";
        import_csv($this->userId, $csv, $this->tz);
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['skipped_duplicates']);
    }

    public function testImportNativeInvalidStoolTypeSkipsRow(): void
    {
        $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
             . "2026-05-27T12:00:00Z,2026-05-27 07:00:00,,8,\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('stool_type', $result['errors'][0]);
    }
```

- [ ] **Step 2: Run the tests — confirm they FAIL**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: FAIL — `import_csv` is not defined.

- [ ] **Step 3: Add `import_csv` to `lib/entries.php`**

Append this function to the end of `lib/entries.php`:

```php
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
    if ($firstCol !== 'occurred_at_utc') {
        $result['errors'][] = "Unrecognized CSV format (first column: \"$firstCol\").";
        fclose($buf);
        return $result;
    }

    $colIdx = array_flip($header);
    $rowNum = 1;

    while (($row = fgetcsv($buf, escape: '\\')) !== false) {
        $rowNum++;

        $occurredAt  = $row[$colIdx['occurred_at_utc']] ?? '';
        $durationSec = ($row[$colIdx['duration_seconds']] ?? '') !== ''
            ? (int) $row[$colIdx['duration_seconds']] : null;
        $stoolType   = (int) ($row[$colIdx['stool_type']] ?? 0);
        $note        = ($row[$colIdx['note']] ?? '') !== '' ? $row[$colIdx['note']] : null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $occurredAt)) {
            $result['errors'][] = "Row $rowNum: invalid occurred_at_utc \"$occurredAt\".";
            continue;
        }
        if ($stoolType < 1 || $stoolType > 7) {
            $result['errors'][] = "Row $rowNum: stool_type \"$stoolType\" is not in range 1–7.";
            continue;
        }

        $stmt = DB::execute(
            'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $occurredAt, $durationSec, $stoolType, $note]
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

- [ ] **Step 4: Run the tests — confirm they PASS**

```bash
./vendor/bin/phpunit tests/ImportTest.php
```

Expected: OK (5 tests, all passing)

- [ ] **Step 5: Run the full test suite**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add lib/entries.php tests/ImportTest.php
git commit -m "feat: add import_csv function with native format support"
```

---

### Task 3: `import_csv` — Poopify format

**Files:**
- Modify: `lib/entries.php`
- Modify: `tests/ImportTest.php`

**Context:** The Poopify CSV header row has 22 quoted columns. When parsed by `fgetcsv`, quotes are stripped. The `Consistency` column maps exactly to Bristol stool types 1–7. `Time on toilet` is in minutes and must be multiplied by 60 for `duration_seconds`. Only rows where `There was stool` is `"Yes"` are imported.

- [ ] **Step 1: Add failing Poopify tests to `tests/ImportTest.php`**

Add this helper constant and five test methods to the `ImportTest` class:

```php
    private string $poopifyHeader = '"Date","Time","There was stool","Color","Consistency","Secondary Consistency","Smell","Volume","Girth","Pain","Stickiness","Hygiene method","Symptoms/Sensations","Time on toilet","Presence of blood","Does it float?","Presence of pieces of food","Excessive flatulence","Presence of mucus","Medication related to this activity","Extra notes","Food information"';

    public function testImportPoopifyHappyPath(): void
    {
        $row = '"2026-05-27","10:00:00","Yes","","Like a smooth, soft sausage or snake","","","","","","","","","5","","","","","","","My note",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $result['imported']);
        $this->assertEmpty($result['errors']);

        $entry = DB::fetch('SELECT * FROM entries WHERE user_id = ?', [$this->userId]);
        $this->assertEquals(4, (int) $entry['stool_type']);
        $this->assertEquals(300, (int) $entry['duration_seconds']); // 5 min * 60
        $this->assertEquals('My note', $entry['note']);
        // occurred_at should be UTC (America/Chicago is UTC-5 in May)
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
            $time = sprintf('%02d:00:00', $hour++);
            $row  = "\"2026-05-27\",\"$time\",\"Yes\",\"\",\"$label\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"";
            $csv  = $this->poopifyHeader . "\n" . $row . "\n";
            $result = import_csv($this->userId, $csv, $this->tz);
            $this->assertEquals(1, $result['imported'], "Failed for consistency: $label");
            $entry = DB::fetch(
                'SELECT stool_type FROM entries WHERE user_id = ? ORDER BY occurred_at DESC LIMIT 1',
                [$this->userId]
            );
            $this->assertEquals($expectedType, (int) $entry['stool_type'], "Wrong type for: $label");
        }
    }

    public function testImportPoopifyThereWasStoolNotYesSkipsRow(): void
    {
        $row = '"2026-05-27","10:00:00","No","","Like a smooth, soft sausage or snake","","","","","","","","","1","","","","","","","",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('There was stool', $result['errors'][0]);
    }

    public function testImportPoopifyUnrecognizedConsistencySkipsRow(): void
    {
        $row = '"2026-05-27","10:00:00","Yes","","Unknown texture","","","","","","","","","1","","","","","","","",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";
        $result = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Unknown texture', $result['errors'][0]);
    }

    public function testImportPoopifyTwiceProducesNoDuplicates(): void
    {
        $row = '"2026-05-27","10:00:00","Yes","","Like a smooth, soft sausage or snake","","","","","","","","","1","","","","","","","",""';
        $csv = $this->poopifyHeader . "\n" . $row . "\n";
        $first  = import_csv($this->userId, $csv, $this->tz);
        $second = import_csv($this->userId, $csv, $this->tz);
        $this->assertEquals(1, $first['imported']);
        $this->assertEquals(0, $second['imported']);
        $this->assertEquals(1, $second['skipped_duplicates']);
        $this->assertEquals(1, (int) DB::fetch('SELECT COUNT(*) as n FROM entries', [])['n']);
    }
```

- [ ] **Step 2: Run the tests — confirm they FAIL**

```bash
./vendor/bin/phpunit tests/ImportTest.php --filter Poopify
```

Expected: FAIL — Poopify CSV header starts with `Date`, which the current code rejects as unrecognized.

- [ ] **Step 3: Replace `import_csv` in `lib/entries.php` with the full implementation**

Replace the entire `import_csv` function with:

```php
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
        $format = 'native';
    } elseif ($firstCol === 'Date') {
        $format = 'poopify';
    } else {
        $result['errors'][] = "Unrecognized CSV format (first column: \"$firstCol\").";
        fclose($buf);
        return $result;
    }

    $colIdx = array_flip($header);
    $consistencyMap = [
        'Separated hard lumps'                 => 1,
        'Lumpy and sausage like'               => 2,
        'Sausage shaped with cracks'           => 3,
        'Like a smooth, soft sausage or snake' => 4,
        'Soft blobs, with clear-cut edges'     => 5,
        'Mushy consistency with ragged edges'  => 6,
        'Liquid, with no solid pieces'         => 7,
    ];
    $rowNum = 1;

    while (($row = fgetcsv($buf, escape: '\\')) !== false) {
        $rowNum++;

        if ($format === 'native') {
            $occurredAt  = $row[$colIdx['occurred_at_utc']] ?? '';
            $durationSec = ($row[$colIdx['duration_seconds']] ?? '') !== ''
                ? (int) $row[$colIdx['duration_seconds']] : null;
            $stoolType   = (int) ($row[$colIdx['stool_type']] ?? 0);
            $note        = ($row[$colIdx['note']] ?? '') !== '' ? $row[$colIdx['note']] : null;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $occurredAt)) {
                $result['errors'][] = "Row $rowNum: invalid occurred_at_utc \"$occurredAt\".";
                continue;
            }
            if ($stoolType < 1 || $stoolType > 7) {
                $result['errors'][] = "Row $rowNum: stool_type \"$stoolType\" is not in range 1–7.";
                continue;
            }
        } else {
            $thereWasStool = $row[$colIdx['There was stool']] ?? '';
            if ($thereWasStool !== 'Yes') {
                $result['errors'][] = "Row $rowNum: skipped (\"There was stool\" is not \"Yes\").";
                continue;
            }

            $consistency = $row[$colIdx['Consistency']] ?? '';
            if (!array_key_exists($consistency, $consistencyMap)) {
                $result['errors'][] = "Row $rowNum: unrecognized consistency \"$consistency\".";
                continue;
            }
            $stoolType = $consistencyMap[$consistency];

            $date = $row[$colIdx['Date']] ?? '';
            $time = $row[$colIdx['Time']] ?? '';
            try {
                $occurredAt = to_utc("$date $time", $timezone);
            } catch (\Exception $e) {
                $result['errors'][] = "Row $rowNum: invalid date/time \"$date $time\".";
                continue;
            }

            $timeOnToilet = $row[$colIdx['Time on toilet']] ?? '';
            $durationSec  = $timeOnToilet !== '' ? (int) $timeOnToilet * 60 : null;
            $extraNotes   = $row[$colIdx['Extra notes']] ?? '';
            $note         = $extraNotes !== '' ? $extraNotes : null;
        }

        $stmt = DB::execute(
            'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $occurredAt, $durationSec, $stoolType, $note]
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

- [ ] **Step 4: Run the Poopify tests — confirm they PASS**

```bash
./vendor/bin/phpunit tests/ImportTest.php --filter Poopify
```

Expected: OK (4 tests, all passing)

- [ ] **Step 5: Run the full test suite**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add lib/entries.php tests/ImportTest.php
git commit -m "feat: add Poopify CSV format support to import_csv"
```

---

### Task 4: HTTP routes, import view, and nav link

**Files:**
- Modify: `index.php`
- Create: `views/import.php`
- Modify: `views/layout.php`

There are no unit tests for routes in this codebase; verify manually after implementation.

- [ ] **Step 1: Add `GET /import` and `POST /import` routes to `index.php`**

Add the following two routes after the existing `$router->get('/export', ...)` block (around line 323):

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
$error  = $error ?? null;
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

- [ ] **Step 3: Add the "Import CSV" link to the nav drawer in `views/layout.php`**

Find the existing Export CSV list item (line 38):

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

Then open `http://localhost:8080` in a browser and verify:

1. Open the nav drawer — "Import CSV" appears below "Export CSV".
2. Navigate to `/import` — the file upload form renders.
3. Upload `tmp/poopify_example.csv` — the result summary shows the number of imported entries with zero errors.
4. Upload the same file again — result shows 0 imported, all entries skipped as duplicates.
5. Export a CSV via `/export`, then import it — all entries skipped as duplicates (none re-inserted).
6. Try uploading an empty file — an appropriate error message is shown.

- [ ] **Step 5: Commit**

```bash
git add index.php views/import.php views/layout.php
git commit -m "feat: add CSV import page with nav drawer link"
```
