<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/backup.php';

class BackupTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_backup_src_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('secret', PASSWORD_BCRYPT)]
        );
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type) VALUES (?, ?, ?)',
            [1, '2026-05-27T12:00:00Z', 4]
        );
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testCreateBackupProducesReadableCopy(): void
    {
        $backupPath = create_backup($this->dbPath);
        try {
            $this->assertFileExists($backupPath);
            $backupPdo = new PDO('sqlite:' . $backupPath);
            $backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $row = $backupPdo->query('SELECT * FROM entries WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('2026-05-27T12:00:00Z', $row['occurred_at']);
        } finally {
            if (file_exists($backupPath)) unlink($backupPath);
        }
    }
}
