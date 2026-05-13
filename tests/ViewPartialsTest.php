<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';

class ViewPartialsTest extends TestCase
{
    private array $config = [
        'base_url' => '',
        'timezone' => 'America/Chicago',
    ];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function renderEntryForm(?array $entry): string
    {
        $config = $this->config;
        $errors = [];
        ob_start();
        include __DIR__ . '/../views/partials/entry-form.php';
        return ob_get_clean();
    }

    // --- Stool type preselection ---

    public function testNewEntryFormDefaultsToType4(): void
    {
        $html = $this->renderEntryForm(null);
        $this->assertStringContainsString('id="stool-type-input" value="4"', $html);
    }

    public function testEditFormPreselectsStoolType(): void
    {
        $html = $this->renderEntryForm([
            'id'               => 1,
            'occurred_at'      => '2026-05-12T19:30:00Z',
            'stool_type'       => 3,
            'duration_seconds' => null,
            'note'             => '',
            'is_edit'          => true,
        ]);
        $this->assertStringContainsString('id="stool-type-input" value="3"', $html);
    }

    public function testCopyFormPreselectsStoolType(): void
    {
        $html = $this->renderEntryForm([
            'id'                  => 1,
            'occurred_at'         => '2026-05-12T19:30:00Z',
            'stool_type'          => 5,
            'duration_seconds'    => 150,
            'note'                => '',
            'is_edit'             => false,
            '_local_occurred_at'  => '2026-05-12T14:30:00',
        ]);
        $this->assertStringContainsString('id="stool-type-input" value="5"', $html);
    }

    // --- Local time display in edit mode ---

    public function testEditFormDisplaysLocalTime(): void
    {
        // UTC 19:30 → CDT 14:30 (America/Chicago = UTC-5 in May)
        $html = $this->renderEntryForm([
            'id'               => 1,
            'occurred_at'      => '2026-05-12T19:30:00Z',
            'stool_type'       => 4,
            'duration_seconds' => null,
            'note'             => '',
            'is_edit'          => true,
        ]);
        $this->assertStringContainsString('value="2026-05-12T14:30"', $html);
    }

    public function testCopyFormUsesSuppledLocalTime(): void
    {
        // _local_occurred_at is used directly (no re-parsing via DateTime)
        $html = $this->renderEntryForm([
            'id'                  => 1,
            'occurred_at'         => '2026-05-12T19:30:00Z',
            'stool_type'          => 4,
            'duration_seconds'    => null,
            'note'                => '',
            'is_edit'             => false,
            '_local_occurred_at'  => '2026-05-12T14:30:00',
        ]);
        $this->assertStringContainsString('value="2026-05-12T14:30"', $html);
    }

    // --- Button mode: Reset vs Cancel ---

    public function testNewEntryFormShowsResetButton(): void
    {
        $html = $this->renderEntryForm(null);
        $this->assertStringContainsString('onclick="resetForm()"', $html);
        $this->assertStringNotContainsString('>Cancel<', $html);
    }

    public function testEditFormShowsCancelNotReset(): void
    {
        $html = $this->renderEntryForm([
            'id'               => 1,
            'occurred_at'      => '2026-05-12T19:30:00Z',
            'stool_type'       => 4,
            'duration_seconds' => null,
            'note'             => '',
            'is_edit'          => true,
        ]);
        $this->assertStringContainsString('>Cancel<', $html);
        $this->assertStringNotContainsString('onclick="resetForm()"', $html);
    }

    public function testCancelButtonLinksToEntriesNew(): void
    {
        $html = $this->renderEntryForm([
            'id'               => 1,
            'occurred_at'      => '2026-05-12T19:30:00Z',
            'stool_type'       => 4,
            'duration_seconds' => null,
            'note'             => '',
            'is_edit'          => true,
        ]);
        $this->assertStringContainsString('hx-get="/entries/new"', $html);
    }

    // --- Form action target ---

    public function testNewEntryFormPostsToEntriesEndpoint(): void
    {
        $html = $this->renderEntryForm(null);
        $this->assertStringContainsString('action="/entries"', $html);
    }

    public function testEditFormPostsToEntryIdEndpoint(): void
    {
        $html = $this->renderEntryForm([
            'id'               => 7,
            'occurred_at'      => '2026-05-12T19:30:00Z',
            'stool_type'       => 4,
            'duration_seconds' => null,
            'note'             => '',
            'is_edit'          => true,
        ]);
        $this->assertStringContainsString('action="/entries/7"', $html);
    }
}
