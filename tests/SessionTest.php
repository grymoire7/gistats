<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/session.php';

class SessionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gistats_session_test_' . uniqid();
        // Other test classes leave a session running; start_app_session must see PHP_SESSION_NONE.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        session_save_path('');
        session_name('PHPSESSID');
        ini_restore('session.gc_maxlifetime');
        ini_restore('session.use_strict_mode');
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testConfigUsesThirtyDayLifetime(): void
    {
        $cfg = session_config_from(['db_path' => '/srv/app/database.sqlite', 'base_url' => ''], []);
        $this->assertSame(2592000, $cfg['gc_maxlifetime']);
        $this->assertSame(2592000, $cfg['cookie']['lifetime']);
    }

    public function testConfigKeepsSessionFilesNextToTheDatabase(): void
    {
        $cfg = session_config_from(['db_path' => '/srv/app/data/database.sqlite', 'base_url' => ''], []);
        $this->assertSame('/srv/app/data/sessions', $cfg['save_path']);
    }

    public function testConfigCookiePathFollowsBasePath(): void
    {
        $cfg = session_config_from(['db_path' => '/srv/database.sqlite', 'base_url' => '/gistats'], []);
        $this->assertSame('/gistats/', $cfg['cookie']['path']);

        $cfg = session_config_from(['db_path' => '/srv/database.sqlite', 'base_url' => ''], []);
        $this->assertSame('/', $cfg['cookie']['path']);
    }

    public function testConfigCookieSecurityFollowsRequestScheme(): void
    {
        $https = session_config_from(['db_path' => '/srv/database.sqlite', 'base_url' => ''], ['HTTPS' => 'on']);
        $this->assertTrue($https['cookie']['secure']);

        $http = session_config_from(['db_path' => '/srv/database.sqlite', 'base_url' => ''], []);
        $this->assertFalse($http['cookie']['secure']);
    }

    public function testConfigCookieIsHttpOnlyAndLax(): void
    {
        $cfg = session_config_from(['db_path' => '/srv/database.sqlite', 'base_url' => ''], []);
        $this->assertTrue($cfg['cookie']['httponly']);
        $this->assertSame('Lax', $cfg['cookie']['samesite']);
        $this->assertSame('gistats_sess', $cfg['name']);
    }

    public function testSessionStoreIsCreatedAndBlockedFromWebAccess(): void
    {
        $dir = $this->root . '/data/sessions';
        ensure_session_store($dir);
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertStringContainsString('Require all denied', file_get_contents($dir . '/.htaccess'));

        ensure_session_store($dir); // idempotent
        $this->assertDirectoryExists($dir);
    }

    public function testStartAppSessionAppliesConfig(): void
    {
        start_app_session(['db_path' => $this->root . '/database.sqlite', 'base_url' => '']);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('gistats_sess', session_name());
        $this->assertSame($this->root . '/sessions', session_save_path());
        $this->assertSame('2592000', ini_get('session.gc_maxlifetime'));
        $params = session_get_cookie_params();
        $this->assertSame(2592000, $params['lifetime']);
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
    }

    public function testLoggedInSessionSlidesItsExpiry(): void
    {
        start_app_session(['db_path' => $this->root . '/database.sqlite', 'base_url' => '']);
        $_SESSION['user_id'] = 1;
        slide_session();
        $this->assertGreaterThan(time() - 10, $_SESSION['last_seen']);
    }
}
