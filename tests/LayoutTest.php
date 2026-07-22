<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

class LayoutTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function renderLayout(array $config): string
    {
        $content = '<p>body</p>';
        ob_start();
        include __DIR__ . '/../views/layout.php';
        return ob_get_clean();
    }

    public function testWordmarkLinksToBaseUrl(): void
    {
        $html = $this->renderLayout(['base_url' => '/gistats']);
        $this->assertStringContainsString('href="/gistats/" style="color:var(--color-text);text-decoration:none;font-size:18px', $html);
    }

    public function testWordmarkLinksToRootWhenBaseUrlEmpty(): void
    {
        $html = $this->renderLayout(['base_url' => '']);
        $this->assertStringContainsString('href="/" style="color:var(--color-text);text-decoration:none;font-size:18px', $html);
    }

    private function render404(array $config): string
    {
        ob_start();
        include __DIR__ . '/../views/404.php';
        return ob_get_clean();
    }

    public function test404HomeLinkUsesBaseUrl(): void
    {
        $html = $this->render404(['base_url' => '/gistats']);
        $this->assertStringContainsString('href="/gistats/" style="color:#00754A;"', $html);
    }
}
