<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/entries.php';

class ExportTest extends TestCase
{
    private string $dbPath;
    private int $userId = 1;
    private string $tz = 'America/New_York';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_export_test_' . uniqid() . '.sqlite';
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

    public function testExportCsvHasCorrectHeader(): void
    {
        $csv   = build_csv_export([], $this->tz);
        $lines = explode("\n", trim($csv));
        $this->assertEquals('occurred_at_utc,occurred_at_local,duration_seconds,stool_type,note', $lines[0]);
    }

    public function testExportCsvHasCorrectDataRow(): void
    {
        create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'duration'    => '05:00',
            'stool_type'  => 4,
            'note'        => 'Test note',
        ], $this->tz);
        $csv   = build_csv_export(get_all_entries($this->userId), $this->tz);
        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('2026-05-12T18:30:00Z', $lines[1]);
        $this->assertStringContainsString('2026-05-12 14:30:00', $lines[1]);
        $this->assertStringContainsString('300', $lines[1]);
        $this->assertStringContainsString('4', $lines[1]);
        $this->assertStringContainsString('Test note', $lines[1]);
    }

    public function testExportCsvContainsNoHtml(): void
    {
        create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $csv = build_csv_export(get_all_entries($this->userId), $this->tz);
        $this->assertStringNotContainsString('<', $csv);
        $this->assertStringNotContainsString('Deprecated', $csv);
    }
}
