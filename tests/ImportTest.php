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
