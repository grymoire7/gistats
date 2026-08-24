<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

class BinResetPasswordTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_bin_reset_password_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('secret', PASSWORD_BCRYPT)]
        );
        DB::reset();
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    private function runScript(array $args): array
    {
        $cmd = array_merge(['php', __DIR__ . '/../bin/reset_password'], $args);
        $proc = proc_open(
            $cmd,
            [STDIN, ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            __DIR__ . '/..',
            array_merge(getenv(), ['GISTATS_DB_PATH' => $this->dbPath])
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return [$exitCode, $stdout, $stderr];
    }

    public function testHelpShowsUsageWithoutTouchingDatabase(): void
    {
        [$exitCode, $stdout] = $this->runScript(['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $stdout);
        $this->assertStringContainsString('username', $stdout);
        $this->assertStringContainsString('password', $stdout);
    }

    public function testMissingArgumentsPrintsUsageAndFails(): void
    {
        [$exitCode, , $stderr] = $this->runScript([]);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $stderr);
    }

    public function testResetsPasswordForExistingUser(): void
    {
        [$exitCode, $stdout] = $this->runScript(['admin', 'newpassword123']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Password updated', $stdout);

        DB::init(['db_path' => $this->dbPath]);
        $this->assertTrue(login('admin', 'newpassword123'));
    }

    public function testUnknownUsernameFails(): void
    {
        [$exitCode, , $stderr] = $this->runScript(['nobody', 'newpassword123']);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('nobody', $stderr);
    }

    public function testShortPasswordFails(): void
    {
        [$exitCode, , $stderr] = $this->runScript(['admin', 'short']);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('8 characters', $stderr);
    }
}
