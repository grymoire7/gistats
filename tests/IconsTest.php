<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

class IconsTest extends TestCase
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

    public function testLayoutLinksSvgFaviconUnderBaseUrl(): void
    {
        $html = $this->renderLayout(['base_url' => '/gistats']);
        $this->assertStringContainsString('<link rel="icon" href="/gistats/icons/favicon.svg" type="image/svg+xml">', $html);
    }

    public function testLayoutLinksAppleTouchIconUnderBaseUrl(): void
    {
        $html = $this->renderLayout(['base_url' => '/gistats']);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/gistats/icons/apple-touch-icon.png">', $html);
    }

    public function testLayoutLinksManifestUnderBaseUrl(): void
    {
        $html = $this->renderLayout(['base_url' => '']);
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
    }

    public function testManifestDeclaresPngIconsThatExist(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.webmanifest'), true);
        $this->assertSame('GI Stats', $manifest['name']);
        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $path = __DIR__ . '/../' . $icon['src'];
            $this->assertFileExists($path);
            $this->assertSame('image/png', $icon['type']);
            $this->assertSame($icon['sizes'], implode('x', array_slice(getimagesize($path), 0, 2)));
            $sizes[] = $icon['sizes'];
        }
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function testManifestUsesRelativeUrlsSoBasePathDoesNotMatter(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.webmanifest'), true);
        $this->assertSame('./', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsNotWith('/', $icon['src']);
        }
    }

    public function testFaviconSvgAndAppleTouchIconExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../icons/favicon.svg');
        $this->assertSame([180, 180], array_slice(getimagesize(__DIR__ . '/../icons/apple-touch-icon.png'), 0, 2));
    }
}
