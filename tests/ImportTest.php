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
    private string $poopifyHeader = '"Date","Time","There was stool","Color","Consistency","Secondary Consistency","Smell","Volume","Girth","Pain","Stickiness","Hygiene method","Symptoms/Sensations","Time on toilet","Presence of blood","Does it float?","Presence of pieces of food","Excessive flatulence","Presence of mucus","Medication related to this activity","Extra notes","Food information"';

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
            $this->assertCount(1, $result['errors'], 'Should report exactly one error for: ' . $case['desc']);
        }
    }

    public function testImportNativeInvalidTimestampSkipsRow(): void
    {
        $cases = [
            ['ts' => '2026-05-27 12:00:00',   'desc' => 'space instead of T'],
            ['ts' => '2026-05-27T12:00:00',    'desc' => 'missing Z'],
            ['ts' => '',                        'desc' => 'empty string'],
        ];
        foreach ($cases as $case) {
            $csv = "occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note\n"
                 . $case['ts'] . ",2026-05-27 07:00:00,,4,\n";
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
        $row    = '"2026-05-27","10:00:00","No","","Like a smooth, soft sousage or snake","","","","","","","","","1","","","","","","","",""';
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
}
