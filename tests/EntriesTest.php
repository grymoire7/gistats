<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/entries.php';

class EntriesTest extends TestCase
{
    private string $dbPath;
    private int $userId = 1;
    private string $tz = 'America/New_York';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_entries_test_' . uniqid() . '.sqlite';
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

    public function testCreateEntryReturnsId(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'duration'    => '05:00',
            'stool_type'  => 4,
            'note'        => 'Test note',
        ], $this->tz);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateEntryStoresUtc(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'duration'    => '05:00',
            'stool_type'  => 4,
            'note'        => null,
        ], $this->tz);
        $row = DB::fetch('SELECT * FROM entries WHERE id = ?', [$id]);
        $this->assertEquals('2026-05-12T18:30:00Z', $row['occurred_at']);
    }

    public function testCreateEntryStoresDurationSeconds(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'duration'    => '07:30',
            'stool_type'  => 4,
        ], $this->tz);
        $row = DB::fetch('SELECT duration_seconds FROM entries WHERE id = ?', [$id]);
        $this->assertEquals(450, $row['duration_seconds']);
    }

    public function testCreateEntryNullDurationAllowed(): void
    {
        $id = create_entry($this->userId, [
            'occurred_at' => '2026-05-12T14:30:00',
            'stool_type'  => 4,
        ], $this->tz);
        $row = DB::fetch('SELECT duration_seconds FROM entries WHERE id = ?', [$id]);
        $this->assertNull($row['duration_seconds']);
    }

    public function testGetEntryReturnsRow(): void
    {
        $id  = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $row = get_entry($id, $this->userId);
        $this->assertNotNull($row);
        $this->assertEquals(4, (int) $row['stool_type']);
    }

    public function testGetEntryReturnsNullForWrongUser(): void
    {
        $id  = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $row = get_entry($id, 999);
        $this->assertNull($row);
    }

    public function testUpdateEntryChangesValues(): void
    {
        $id = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $ok = update_entry($id, $this->userId, [
            'occurred_at' => '2026-05-12T15:00:00',
            'stool_type'  => 3,
            'note'        => 'Updated',
        ], $this->tz);
        $this->assertTrue($ok);
        $row = get_entry($id, $this->userId);
        $this->assertEquals(3, (int) $row['stool_type']);
        $this->assertEquals('Updated', $row['note']);
    }

    public function testUpdateEntryReturnsFalseForWrongUser(): void
    {
        $id = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $ok = update_entry($id, 999, ['occurred_at' => '2026-05-12T15:00:00', 'stool_type' => 4], $this->tz);
        $this->assertFalse($ok);
    }

    public function testDeleteEntryRemovesRow(): void
    {
        $id = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $ok = delete_entry($id, $this->userId);
        $this->assertTrue($ok);
        $this->assertNull(get_entry($id, $this->userId));
    }

    public function testDeleteEntryReturnsFalseForWrongUser(): void
    {
        $id = create_entry($this->userId, ['occurred_at' => '2026-05-12T14:30:00', 'stool_type' => 4], $this->tz);
        $ok = delete_entry($id, 999);
        $this->assertFalse($ok);
    }

    public function testGetEntriesPagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            create_entry($this->userId, ['occurred_at' => '2026-05-12T14:00:00', 'stool_type' => 4], $this->tz);
        }
        $page1 = get_entries($this->userId, 0, 3);
        $page2 = get_entries($this->userId, 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    public function testGetEntriesFilteredByDate(): void
    {
        create_entry($this->userId, ['occurred_at' => '2026-05-12T14:00:00', 'stool_type' => 4], $this->tz);
        create_entry($this->userId, ['occurred_at' => '2026-05-13T14:00:00', 'stool_type' => 3], $this->tz);
        $results = get_entries($this->userId, 0, 30, '2026-05-12', $this->tz);
        $this->assertCount(1, $results);
        $this->assertEquals(4, (int) $results[0]['stool_type']);
    }

    public function testGetEntriesForMonthReturnsOnlyThatMonth(): void
    {
        create_entry($this->userId, ['occurred_at' => '2026-05-12T14:00:00', 'stool_type' => 4], $this->tz);
        create_entry($this->userId, ['occurred_at' => '2026-06-01T14:00:00', 'stool_type' => 3], $this->tz);
        $results = get_entries_for_month($this->userId, 2026, 5, $this->tz);
        $this->assertCount(1, $results);
    }

    public function testCountEntries(): void
    {
        for ($i = 0; $i < 4; $i++) {
            create_entry($this->userId, ['occurred_at' => '2026-05-12T14:00:00', 'stool_type' => 4], $this->tz);
        }
        $this->assertEquals(4, count_entries($this->userId));
    }
}
