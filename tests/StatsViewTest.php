<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/helpers.php';

class StatsViewTest extends TestCase
{
    private function render(array $overrides = []): string
    {
        $data = array_merge([
            'config'    => ['base_url' => '/gistats', 'timezone' => 'America/Chicago'],
            'types'     => ['4', '3'],
            'movingAvg' => [4.0, 3.5],
            'typeFreq'  => [1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 0, 6 => 0, 7 => 0],
            'dailyFreq' => ['2026-05-29' => 1, '2026-05-30' => 1],
            'range'     => ['start' => '2026-05-01', 'end' => '2026-05-30'],
            'today'     => '2026-05-30',
            'earliest'  => '2025-01-01',
            'preset'    => 'last:30',
        ], $overrides);
        extract($data);
        ob_start();
        include __DIR__ . '/../views/stats.php';
        return ob_get_clean();
    }

    public function testShowsStartAndEndDateInputsWithCurrentRange(): void
    {
        $html = $this->render();
        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*name="start"[^>]*value="2026-05-01"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*name="end"[^>]*value="2026-05-30"/', $html);
    }

    public function testDateInputsCannotPickFutureDates(): void
    {
        $html = $this->render();
        $this->assertSame(2, preg_match_all('/type="date"[^>]*max="2026-05-30"/', $html));
    }

    public function testFormSubmitsByGetToStatsUnderBasePath(): void
    {
        $html = $this->render();
        $this->assertMatchesRegularExpression('/<form[^>]*method="get"[^>]*action="\/gistats\/stats"/', $html);
    }

    public function testDaysSelectOffersAllOptionsAndSelectsCurrent(): void
    {
        $html = $this->render(['preset' => 'last:90', 'range' => last_n_days_range(90, '2026-05-30')]);
        foreach ([30, 60, 90, 180, 365] as $n) {
            $this->assertStringContainsString(">$n</option>", $html);
        }
        $this->assertMatchesRegularExpression('/<option value="90" selected>/', $html);
        $this->assertSame(1, substr_count($html, ' selected>'));
    }

    public function testDaysSelectDefaultsTo30WhenRangeIsNotAPreset(): void
    {
        $html = $this->render(['preset' => null]);
        $this->assertMatchesRegularExpression('/<option value="30" selected>/', $html);
    }

    public function testLastButtonIsActiveForLastPreset(): void
    {
        $html = $this->render(['preset' => 'last:30']);
        $this->assertMatchesRegularExpression('/<button[^>]*value="last"[^>]*aria-pressed="true"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*value="all"[^>]*aria-pressed="false"/', $html);
    }

    public function testAllTimeButtonIsActiveForAllPreset(): void
    {
        $html = $this->render(['preset' => 'all']);
        $this->assertMatchesRegularExpression('/<button[^>]*value="all"[^>]*aria-pressed="true"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*value="last"[^>]*aria-pressed="false"/', $html);
    }

    public function testNeitherButtonActiveForCustomRange(): void
    {
        $html = $this->render(['preset' => null]);
        $this->assertStringNotContainsString('aria-pressed="true"', $html);
    }

    public function testDailyChartTitleNoLongerSaysLast30Days(): void
    {
        $html = $this->render();
        $this->assertStringNotContainsString('last 30 days', $html);
        $this->assertStringContainsString('Daily Frequency', $html);
    }

    public function testEmptyRangeKeepsControlsAndExplainsNoData(): void
    {
        $html = $this->render(['types' => [], 'movingAvg' => [], 'dailyFreq' => ['2026-05-30' => 0]]);
        $this->assertStringContainsString('No entries in this range', $html);
        $this->assertStringContainsString('name="start"', $html);
        $this->assertStringNotContainsString('<canvas', $html);
    }

    public function testNoEntriesAtAllShowsOriginalMessage(): void
    {
        $html = $this->render(['types' => [], 'movingAvg' => [], 'earliest' => null]);
        $this->assertStringContainsString('No entries yet', $html);
        $this->assertStringNotContainsString('name="start"', $html);
    }
}
