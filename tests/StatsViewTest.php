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
            'dailyUrgent' => ['2026-05-29' => 0, '2026-05-30' => 1],
            'urgencies' => [0, 1],
            'heatmap'   => build_heatmap([
                ['occurred_at' => '2026-05-29T15:00:00Z', 'stool_type' => 3, 'urgency' => 0],
                ['occurred_at' => '2026-05-30T15:00:00Z', 'stool_type' => 6, 'urgency' => 1],
            ], 'America/Chicago', '2026-05-29', '2026-05-30'),
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

    // --- Urgency: charts ---

    public function testMovingAverageChartMarksUrgentPointsInUrgencyColor(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('const urgentFlags = [0,1];', $html);
        $this->assertStringContainsString("'" . URGENCY_COLOR . "'", $html);
        $this->assertStringContainsString('pointBackgroundColor: urgentFlags.map', $html);
    }

    public function testDailyChartIsStackedWithUrgentSegment(): void
    {
        $html = $this->render();
        $this->assertStringContainsString("label: 'Not urgent'", $html);
        $this->assertStringContainsString("label: 'Urgent'", $html);
        $this->assertStringContainsString('const dailyNotUrgent = [1,0];', $html);
        $this->assertStringContainsString('const dailyUrgent = [0,1];', $html);
        $this->assertSame(2, substr_count($html, 'stacked: true'));
    }

    // --- Urgency: heat map ---

    public function testHeatmapCardIsRenderedWithOneCellPerDay(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Daily Heat Map', $html);
        $this->assertSame(2, substr_count($html, 'class="heatmap-cell"'));
    }

    public function testHeatmapCellUsesTheColorForItsLargestType(): void
    {
        $html = $this->render();
        $this->assertMatchesRegularExpression(
            '/data-date="2026-05-30"[^>]*data-type="6"[^>]*background:' . preg_quote(HEATMAP_TYPE_COLORS[6], '/') . '/',
            $html
        );
    }

    public function testUrgentDayHasDotAndAccessibleLabel(): void
    {
        $html = $this->render();
        $this->assertSame(1, substr_count($html, 'class="heatmap-dot"'));
        $this->assertStringContainsString('aria-label="Sat, May 30, 2026: Type 6, urgency"', $html);
        $this->assertStringContainsString('aria-label="Fri, May 29, 2026: Type 3"', $html);
    }

    public function testDayWithoutEntriesIsLabeledAndHasNoTypeColor(): void
    {
        $html = $this->render([
            'heatmap' => build_heatmap([], 'America/Chicago', '2026-05-30', '2026-05-30'),
        ]);
        $this->assertStringContainsString('aria-label="Sat, May 30, 2026: no entries"', $html);
        $this->assertStringNotContainsString('data-type=', $html);
    }

    public function testHeatmapScrollsSidewaysAndShowsLegend(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('overflow-x:auto', $html);
        foreach (HEATMAP_TYPE_COLORS as $color) {
            $this->assertStringContainsString('class="heatmap-swatch" style="background:' . $color, $html);
        }
        $this->assertStringContainsString('Urgent', $html);
    }

    public function testCellsArePlacedOnTheGridByWeekdayRowAndWeekColumn(): void
    {
        // Rows 2-8 are Sun-Sat (row 1 is the month labels); column 1 is the weekday labels.
        $html = $this->render();
        $this->assertMatchesRegularExpression('/data-date="2026-05-29"[^>]*grid-column:2;grid-row:7;/', $html); // Fri
        $this->assertMatchesRegularExpression('/data-date="2026-05-30"[^>]*grid-column:2;grid-row:8;/', $html); // Sat
    }

    public function testWeekdayLabelsAreShownForMonWedFri(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('class="heatmap-weekday" style="grid-row:3;">Mon<', $html);
        $this->assertStringContainsString('class="heatmap-weekday" style="grid-row:5;">Wed<', $html);
        $this->assertStringContainsString('class="heatmap-weekday" style="grid-row:7;">Fri<', $html);
        $this->assertStringNotContainsString('>Tue<', $html);
    }

    public function testMonthLabelsSitAboveTheirWeekColumn(): void
    {
        $html = $this->render([
            'heatmap' => build_heatmap([], 'America/Chicago', '2026-05-03', '2026-06-13'),
        ]);
        $this->assertStringContainsString('class="heatmap-month" style="grid-column:2;">May<', $html);
        $this->assertStringContainsString('class="heatmap-month" style="grid-column:6;">Jun<', $html);
    }

    public function testCellsHaveCustomTooltipTextAndAreFocusable(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('id="heatmap-tooltip"', $html);
        $this->assertStringContainsString('data-tip="Sat, May 30, 2026: Type 6, urgency"', $html);
        $this->assertSame(2, substr_count($html, 'class="heatmap-cell"'));
        $this->assertSame(2, substr_count($html, 'tabindex="0"'));
    }

    public function testCellsNoLongerUseTheSlowNativeTitleTooltip(): void
    {
        $html = $this->render();
        $this->assertDoesNotMatchRegularExpression('/class="heatmap-cell"[^>]*\stitle=/', $html);
    }

    public function testUrgentSegmentIsFirstDatasetSoItSitsOnTheAxis(): void
    {
        $html = $this->render();
        $this->assertLessThan(strpos($html, "label: 'Not urgent'"), strpos($html, "label: 'Urgent'"));
    }

    public function testHeatmapSubtitleSaysLoosestStoolType(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Each day is colored by its loosest stool type', $html);
        $this->assertStringNotContainsString('largest stool type', $html);
    }
}
