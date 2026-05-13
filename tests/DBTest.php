<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';

class DBTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_test_' . uniqid() . '.sqlite';
        DB::reset();
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    private function config(): array
    {
        return ['db_path' => $this->dbPath];
    }

    public function testInitReturnsPDO(): void
    {
        $pdo = DB::init($this->config());
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testInitIsSingleton(): void
    {
        $pdo1 = DB::init($this->config());
        $pdo2 = DB::init($this->config());
        $this->assertSame($pdo1, $pdo2);
    }

    public function testCreateSchemaCreatesUsersTable(): void
    {
        DB::init($this->config());
        DB::createSchema();
        $result = DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $this->assertNotNull($result);
    }

    public function testCreateSchemaCreatesEntriesTable(): void
    {
        DB::init($this->config());
        DB::createSchema();
        $result = DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='entries'");
        $this->assertNotNull($result);
    }

    public function testFetchReturnsNullWhenNotFound(): void
    {
        DB::init($this->config());
        DB::createSchema();
        $result = DB::fetch('SELECT * FROM users WHERE id = ?', [999]);
        $this->assertNull($result);
    }

    public function testFetchAllReturnsArray(): void
    {
        DB::init($this->config());
        DB::createSchema();
        $results = DB::fetchAll('SELECT * FROM users');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testExecuteAndLastInsertId(): void
    {
        DB::init($this->config());
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('secret', PASSWORD_BCRYPT)]
        );
        $id = (int) DB::lastInsertId();
        $this->assertGreaterThan(0, $id);
        $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertEquals('admin', $user['username']);
    }

    public function testResetAllowsReinit(): void
    {
        DB::init($this->config());
        DB::reset();
        $path2 = sys_get_temp_dir() . '/gistats_test2_' . uniqid() . '.sqlite';
        $pdo = DB::init(['db_path' => $path2]);
        $this->assertInstanceOf(PDO::class, $pdo);
        if (file_exists($path2)) unlink($path2);
    }
}
