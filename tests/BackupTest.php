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

    public function testValidateSqliteUploadAcceptsValidDatabase(): void
    {
        $error = validate_sqlite_upload($this->dbPath);
        $this->assertNull($error);
    }

    public function testValidateSqliteUploadRejectsNonSqliteFile(): void
    {
        $fakePath = sys_get_temp_dir() . '/gistats_not_sqlite_' . uniqid() . '.txt';
        file_put_contents($fakePath, 'not a database');
        try {
            $error = validate_sqlite_upload($fakePath);
            $this->assertNotNull($error);
        } finally {
            unlink($fakePath);
        }
    }

    public function testValidateSqliteUploadRejectsWrongSchema(): void
    {
        $wrongSchemaPath = sys_get_temp_dir() . '/gistats_wrong_schema_' . uniqid() . '.sqlite';
        $pdo = new PDO('sqlite:' . $wrongSchemaPath);
        $pdo->exec('CREATE TABLE something_else (id INTEGER)');
        unset($pdo);
        try {
            $error = validate_sqlite_upload($wrongSchemaPath);
            $this->assertNotNull($error);
        } finally {
            unlink($wrongSchemaPath);
        }
    }

    public function testRestoreFromUploadReplacesLiveDatabase(): void
    {
        // Build the "upload" the same way a real one originates: a source db, backed up via create_backup().
        $sourcePath = sys_get_temp_dir() . '/gistats_restore_source_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $sourcePath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['restored-user', password_hash('x', PASSWORD_BCRYPT)]
        );
        $uploadPath = create_backup($sourcePath);
        DB::reset();
        unlink($sourcePath);

        try {
            restore_from_upload($this->dbPath, $uploadPath);

            $this->assertFileDoesNotExist($this->dbPath . '-wal');
            $this->assertFileDoesNotExist($this->dbPath . '-shm');

            DB::init(['db_path' => $this->dbPath]);
            $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['restored-user']);
            $this->assertNotNull($user);
        } finally {
            if (file_exists($uploadPath)) unlink($uploadPath);
        }
    }

    public function testRestoreFromUploadReturnsFalseOnCopyFailure(): void
    {
        // NOTE: we can't compare raw file_get_contents() bytes before/after here.
        // restore_from_upload() calls DB::reset() unconditionally up front, which
        // closes the last PDO connection to $this->dbPath and triggers SQLite's
        // automatic WAL checkpoint -- that legitimately rewrites the main db
        // file's on-disk bytes (e.g. 4096 -> 28672 bytes in a manual check) even
        // when nothing goes wrong. So we assert on the thing that actually
        // matters: the live database is still intact and readable afterward.
        $nonExistentUpload = sys_get_temp_dir() . '/gistats_does_not_exist_' . uniqid() . '.sqlite';

        $result = @restore_from_upload($this->dbPath, $nonExistentUpload);

        $this->assertFalse($result);

        DB::init(['db_path' => $this->dbPath]);
        $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['admin']);
        $this->assertNotNull($user, 'Original data should survive a failed restore attempt.');
    }
}
