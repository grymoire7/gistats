<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/helpers.php';

class HelpersTest extends TestCase
{
    // --- UTC conversion ---

    public function testToUtcConvertsLocalToUtc(): void
    {
        $utc = to_utc('2026-05-12T14:30:00', 'America/New_York');
        $this->assertEquals('2026-05-12T18:30:00Z', $utc);
    }

    public function testFromUtcConvertsToLocal(): void
    {
        $dt = from_utc('2026-05-12T18:30:00Z', 'America/New_York');
        $this->assertEquals('2026-05-12T14:30:00', $dt->format('Y-m-d\TH:i:s'));
        $this->assertEquals('America/New_York', $dt->getTimezone()->getName());
    }

    public function testToUtcWithBrowserTimezone(): void
    {
        // Simulates cookie-supplied timezone overriding config default
        $utc = to_utc('2026-05-12T14:30:00', 'America/Chicago');
        $this->assertEquals('2026-05-12T19:30:00Z', $utc); // CDT = UTC-5
    }

    public function testFromUtcWithBrowserTimezone(): void
    {
        $dt = from_utc('2026-05-12T19:30:00Z', 'America/Chicago');
        $this->assertEquals('2026-05-12T14:30:00', $dt->format('Y-m-d\TH:i:s'));
        $this->assertEquals('America/Chicago', $dt->getTimezone()->getName());
    }

    // --- Duration ---

    public function testDurationToSecondsMMSS(): void
    {
        $this->assertEquals(300,  duration_to_seconds('05:00'));
        $this->assertEquals(330,  duration_to_seconds('05:30'));
        $this->assertEquals(0,    duration_to_seconds('00:00'));
        $this->assertEquals(3599, duration_to_seconds('59:59'));
    }

    public function testDurationToSecondsHMMSS(): void
    {
        $this->assertEquals(3600,   duration_to_seconds('1:00:00'));
        $this->assertEquals(3930,   duration_to_seconds('1:05:30'));
        $this->assertEquals(36000,  duration_to_seconds('10:00:00'));
        $this->assertEquals(360000, duration_to_seconds('100:00:00'));
    }

    public function testSecondsToDurationUnderOneHour(): void
    {
        $this->assertEquals('05:00', seconds_to_duration(300));
        $this->assertEquals('05:30', seconds_to_duration(330));
        $this->assertEquals('00:00', seconds_to_duration(0));
        $this->assertEquals('59:59', seconds_to_duration(3599));
    }

    public function testSecondsToDurationOneHourAndOver(): void
    {
        $this->assertEquals('1:00:00', seconds_to_duration(3600));
        $this->assertEquals('1:05:30', seconds_to_duration(3930));
        $this->assertEquals('10:00:00', seconds_to_duration(36000));
        $this->assertEquals('100:00:00', seconds_to_duration(360000));
    }

    // --- Dominant type ---

    public function testDominantTypeEmptyReturnsDefault(): void
    {
        $this->assertEquals(4, dominant_type([]));
    }

    public function testDominantTypeReturnsMostFrequent(): void
    {
        $this->assertEquals(4, dominant_type([4, 4, 3]));
        $this->assertEquals(3, dominant_type([3, 3, 4, 4, 3]));
    }

    public function testDominantTypeTieBreaksFurthestFromFour(): void
    {
        // Types 2 and 6 are tied — both distance 2 from 4, pick 2 (lower index is fine,
        // but the real rule is: further is more extreme — 1 and 7 > 2 and 6)
        // Types 1 and 7 both distance 3 from 4; types 2 and 6 both distance 2
        // Tie between type 1 (dist 3) and type 6 (dist 2): type 1 wins
        $this->assertEquals(1, dominant_type([1, 6]));
        // Tie between type 3 (dist 1) and type 5 (dist 1): equal distance, return either
        // (just ensure it returns a valid type)
        $result = dominant_type([3, 5]);
        $this->assertContains($result, [3, 5]);
    }

    public function testDominantTypeSingleElement(): void
    {
        $this->assertEquals(4, dominant_type([4]));
        $this->assertEquals(7, dominant_type([7]));
    }

    // --- Moving average ---

    public function testMovingAverageWindow7(): void
    {
        $types = [4, 4, 4, 4, 4, 4, 4];
        $avg = moving_average($types, 7);
        $this->assertEquals([4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 4.0], $avg);
    }

    public function testMovingAverageStartupFewerThan7(): void
    {
        $types = [2, 4];
        $avg = moving_average($types, 7);
        // Index 0: avg of [2] = 2.0
        // Index 1: avg of [2, 4] = 3.0
        $this->assertEquals([2.0, 3.0], $avg);
    }

    public function testMovingAverageEmptyInput(): void
    {
        $this->assertEquals([], moving_average([], 7));
    }

    public function testMovingAverageCalculation(): void
    {
        $types = [1, 2, 3, 4, 5, 6, 7, 4];
        $avg = moving_average($types, 7);
        // Index 6: avg of [1,2,3,4,5,6,7] = 28/7 = 4.0
        $this->assertEquals(4.0, $avg[6]);
        // Index 7: avg of [2,3,4,5,6,7,4] = 31/7 ≈ 4.43
        $this->assertEquals(round(31/7, 2), $avg[7]);
    }

    // --- Build calendar ---

    public function testBuildCalendarStructure(): void
    {
        $calendar = build_calendar(2026, 5, [], 'America/New_York');
        $this->assertArrayHasKey('start_dow', $calendar);
        $this->assertArrayHasKey('days', $calendar);
        $this->assertCount(31, $calendar['days']); // May has 31 days
        $this->assertEquals('2026-05-01', $calendar['days'][0]['date']);
        $this->assertEquals('2026-05-31', $calendar['days'][30]['date']);
    }

    public function testBuildCalendarEmptyDays(): void
    {
        $calendar = build_calendar(2026, 5, [], 'America/New_York');
        foreach ($calendar['days'] as $day) {
            $this->assertEquals(0, $day['count']);
            $this->assertNull($day['dominant_type']);
        }
    }

    public function testBuildCalendarWithEntries(): void
    {
        $entries = [
            ['occurred_at' => '2026-05-12T18:30:00Z', 'stool_type' => 4],
            ['occurred_at' => '2026-05-12T20:00:00Z', 'stool_type' => 3],
        ];
        $calendar = build_calendar(2026, 5, $entries, 'America/New_York');
        $day12 = $calendar['days'][11]; // index 11 = day 12
        $this->assertEquals(2, $day12['count']);
        $this->assertEquals('2026-05-12', $day12['date']);
        // Both types once: 4 and 3; distance from 4: 0 and 1; type 3 is farther
        $this->assertEquals(3, $day12['dominant_type']);
    }

    // --- Type frequency ---

    public function testTypeFrequencyAllZeroes(): void
    {
        $freq = type_frequency([]);
        $this->assertEquals([1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0], $freq);
    }

    public function testTypeFrequencyCounts(): void
    {
        $entries = [
            ['stool_type' => 4],
            ['stool_type' => 4],
            ['stool_type' => 3],
        ];
        $freq = type_frequency($entries);
        $this->assertEquals(2, $freq[4]);
        $this->assertEquals(1, $freq[3]);
        $this->assertEquals(0, $freq[1]);
    }

    // --- Daily frequency ---

    public function testDailyFrequencyHasOneKeyPerDayInclusive(): void
    {
        $freq = daily_frequency([], 'America/New_York', '2026-05-10', '2026-05-12');
        $this->assertSame(['2026-05-10', '2026-05-11', '2026-05-12'], array_keys($freq));
    }

    public function testDailyFrequencyCountsEntriesByLocalDate(): void
    {
        // 2026-05-12T02:00Z is still May 11 in New York.
        $entries = [
            ['occurred_at' => '2026-05-12T02:00:00Z'],
            ['occurred_at' => '2026-05-12T15:00:00Z'],
            ['occurred_at' => '2026-05-12T16:00:00Z'],
        ];
        $freq = daily_frequency($entries, 'America/New_York', '2026-05-11', '2026-05-12');
        $this->assertSame(['2026-05-11' => 1, '2026-05-12' => 2], $freq);
    }

    public function testDailyFrequencyIgnoresEntriesOutsideRange(): void
    {
        $entries = [['occurred_at' => '2026-05-20T15:00:00Z']];
        $freq = daily_frequency($entries, 'America/New_York', '2026-05-11', '2026-05-12');
        $this->assertSame(0, array_sum($freq));
    }

    // --- Urgency on stats ---

    public function testDailyUrgentFrequencyCountsOnlyUrgentEntriesPerLocalDay(): void
    {
        $entries = [
            ['occurred_at' => '2026-05-12T15:00:00Z', 'urgency' => 1],
            ['occurred_at' => '2026-05-12T16:00:00Z', 'urgency' => 0],
            ['occurred_at' => '2026-05-12T17:00:00Z', 'urgency' => 1],
            ['occurred_at' => '2026-05-12T02:00:00Z', 'urgency' => 1], // May 11 in New York
        ];
        $freq = daily_urgent_frequency($entries, 'America/New_York', '2026-05-11', '2026-05-13');
        $this->assertSame(['2026-05-11' => 1, '2026-05-12' => 2, '2026-05-13' => 0], $freq);
    }

    public function testHeatmapPadsFirstWeekSoDaysLandOnTheirWeekday(): void
    {
        // 2026-05-01 is a Friday; weeks start on Sunday.
        $weeks = build_heatmap([], 'America/Chicago', '2026-05-01', '2026-05-04');
        $this->assertCount(2, $weeks);
        $this->assertCount(7, $weeks[0]);
        $this->assertSame([null, null, null, null, null], array_slice($weeks[0], 0, 5));
        $this->assertSame('2026-05-01', $weeks[0][5]['date']);
        $this->assertSame('2026-05-02', $weeks[0][6]['date']);
        $this->assertSame('2026-05-03', $weeks[1][0]['date']);
        $this->assertSame('2026-05-04', $weeks[1][1]['date']);
        $this->assertSame([null, null, null, null, null], array_slice($weeks[1], 2));
    }

    public function testHeatmapDayWithoutEntriesHasNoTypeAndNoUrgency(): void
    {
        $weeks = build_heatmap([], 'America/Chicago', '2026-05-03', '2026-05-03');
        $this->assertSame(['date' => '2026-05-03', 'type' => null, 'urgent' => false], $weeks[0][0]);
    }

    public function testHeatmapColorsDayByLargestTypeNotAverage(): void
    {
        $entries = [
            ['occurred_at' => '2026-05-03T15:00:00Z', 'stool_type' => 1, 'urgency' => 0],
            ['occurred_at' => '2026-05-03T18:00:00Z', 'stool_type' => 7, 'urgency' => 0],
            ['occurred_at' => '2026-05-03T20:00:00Z', 'stool_type' => 3, 'urgency' => 0],
        ];
        $weeks = build_heatmap($entries, 'America/Chicago', '2026-05-03', '2026-05-03');
        $this->assertSame(7, $weeks[0][0]['type']);
    }

    public function testHeatmapDayIsUrgentIfAnyEntryIsUrgent(): void
    {
        $entries = [
            ['occurred_at' => '2026-05-03T15:00:00Z', 'stool_type' => 4, 'urgency' => 0],
            ['occurred_at' => '2026-05-03T18:00:00Z', 'stool_type' => 4, 'urgency' => 1],
        ];
        $weeks = build_heatmap($entries, 'America/Chicago', '2026-05-03', '2026-05-03');
        $this->assertTrue($weeks[0][0]['urgent']);
    }

    public function testHeatmapUsesLocalDateForBucketing(): void
    {
        // 02:00Z on May 4 is 21:00 on May 3 in Chicago.
        $entries = [['occurred_at' => '2026-05-04T02:00:00Z', 'stool_type' => 5, 'urgency' => 0]];
        $weeks = build_heatmap($entries, 'America/Chicago', '2026-05-03', '2026-05-04');
        $this->assertSame(5, $weeks[0][0]['type']);
        $this->assertNull($weeks[0][1]['type']);
    }

    public function testHeatmapMonthLabelsMarkTheColumnContainingTheFirst(): void
    {
        $weeks = build_heatmap([], 'America/Chicago', '2026-05-03', '2026-06-13');
        $this->assertSame(
            [['col' => 0, 'label' => 'May'], ['col' => 4, 'label' => 'Jun']],
            heatmap_month_labels($weeks)
        );
    }

    public function testHeatmapMonthLabelsForSingleMonthIsJustThatMonth(): void
    {
        $weeks = build_heatmap([], 'America/Chicago', '2026-05-03', '2026-05-30');
        $this->assertSame([['col' => 0, 'label' => 'May']], heatmap_month_labels($weeks));
    }

    public function testHeatmapMonthLabelsDropsAnEarlierLabelThatWouldCollide(): void
    {
        // Starts Wed May 27; the very next column holds Jun 1, so "May" would overlap "Jun".
        $weeks = build_heatmap([], 'America/Chicago', '2026-05-27', '2026-06-10');
        $this->assertSame([['col' => 1, 'label' => 'Jun']], heatmap_month_labels($weeks));
    }

    public function testHeatmapMonthLabelsEmptyForNoWeeks(): void
    {
        $this->assertSame([], heatmap_month_labels([]));
    }

    public function testHeatmapHasAColorForEveryStoolType(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys(HEATMAP_TYPE_COLORS));
    }

    public function testUrgencyColorIsFarInHueFromEveryHeatmapColor(): void
    {
        $hue = function (string $hex): float {
            [$r, $g, $b] = array_map(fn($c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
            $max = max($r, $g, $b);
            $d   = $max - min($r, $g, $b);
            if ($d == 0) return 0.0;
            $h = match ($max) {
                $r => fmod(($g - $b) / $d, 6),
                $g => ($b - $r) / $d + 2,
                default => ($r - $g) / $d + 4,
            };
            return fmod($h * 60 + 360, 360);
        };
        $urgentHue = $hue(URGENCY_COLOR);
        foreach (HEATMAP_TYPE_COLORS as $type => $color) {
            $diff = abs($urgentHue - $hue($color));
            $diff = min($diff, 360 - $diff);
            $this->assertGreaterThanOrEqual(60, $diff, "Type $type ($color) is too close to the urgency color");
        }
    }

    public function testHeatmapColorsStayVisibleOnTheDarkCard(): void
    {
        $lum = function (string $hex): float {
            $c = array_map(function ($v) {
                $v = hexdec($v) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $card = $lum('#2a2a2a');
        foreach (HEATMAP_TYPE_COLORS as $type => $color) {
            $ratio = ($lum($color) + 0.05) / ($card + 0.05);
            $this->assertGreaterThanOrEqual(2.4, $ratio, "Type $type ($color) fades into the card");
        }
    }

    // --- Stats range ---

    public function testResolveStatsRangeDefaultsToLast30DaysInclusiveOfToday(): void
    {
        $r = resolve_stats_range(null, null, '2026-05-30');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testResolveStatsRangeUsesValidInput(): void
    {
        $r = resolve_stats_range('2026-01-05', '2026-02-10', '2026-05-30');
        $this->assertSame(['start' => '2026-01-05', 'end' => '2026-02-10'], $r);
    }

    public function testResolveStatsRangeFallsBackToDefaultOnGarbage(): void
    {
        $r = resolve_stats_range('nope', '2026-13-45', '2026-05-30');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testResolveStatsRangeClampsFutureEndToToday(): void
    {
        $r = resolve_stats_range('2026-05-01', '2026-12-31', '2026-05-30');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testResolveStatsRangeMovesStartToEndWhenStartAfterEnd(): void
    {
        $r = resolve_stats_range('2026-05-20', '2026-05-10', '2026-05-30');
        $this->assertSame(['start' => '2026-05-10', 'end' => '2026-05-10'], $r);
    }

    public function testResolveStatsRangeWithOnlyEndKeepsDefaultSpanLength(): void
    {
        $r = resolve_stats_range(null, '2026-03-31', '2026-05-30');
        $this->assertSame(['start' => '2026-03-02', 'end' => '2026-03-31'], $r);
    }

    public function testStatsRangeFromRequestDefaultsToLast30Days(): void
    {
        $r = stats_range_from_request([], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestLastPresetUsesDays(): void
    {
        $r = stats_range_from_request(['preset' => 'last', 'days' => '90'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2026-03-02', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestLastPresetRejectsUnlistedDays(): void
    {
        $r = stats_range_from_request(['preset' => 'last', 'days' => '45'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestAllPresetSpansEarliestToToday(): void
    {
        $r = stats_range_from_request(['preset' => 'all'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2025-01-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestAllPresetWithNoDataIsJustToday(): void
    {
        $r = stats_range_from_request(['preset' => 'all'], '2026-05-30', null);
        $this->assertSame(['start' => '2026-05-30', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestUsesManualDatesWithoutPreset(): void
    {
        $r = stats_range_from_request(['start' => '2026-01-05', 'end' => '2026-02-10'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2026-01-05', 'end' => '2026-02-10'], $r);
    }

    public function testStatsRangeFromRequestPresetWinsOverDates(): void
    {
        $r = stats_range_from_request(
            ['preset' => 'all', 'start' => '2026-01-05', 'end' => '2026-02-10'],
            '2026-05-30', '2025-01-01'
        );
        $this->assertSame(['start' => '2025-01-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestIgnoresArrayValuesInsteadOfCrashing(): void
    {
        $r = stats_range_from_request(['start' => ['x'], 'end' => ['y']], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestClampsExplicitStartToEarliestEntry(): void
    {
        $r = stats_range_from_request(['start' => '0001-01-01'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2025-01-01', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestWithNoDataCollapsesExplicitStartToEnd(): void
    {
        $r = stats_range_from_request(['start' => '1900-01-01'], '2026-05-30', null);
        $this->assertSame(['start' => '2026-05-30', 'end' => '2026-05-30'], $r);
    }

    public function testStatsRangeFromRequestRangeEntirelyBeforeEarliestCollapsesToEnd(): void
    {
        $r = stats_range_from_request(['start' => '2020-01-01', 'end' => '2020-02-01'], '2026-05-30', '2025-01-01');
        $this->assertSame(['start' => '2020-02-01', 'end' => '2020-02-01'], $r);
    }

    public function testStatsRangeFromRequestDefaultIsNotClampedToEarliest(): void
    {
        // A brand-new user (3 days of data) still gets "Last 30 days", not "All Time".
        $r = stats_range_from_request([], '2026-05-30', '2026-05-28');
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-30'], $r);
    }

    public function testDailyFrequencyKeepsLastDayWhenMidnightDoesNotExist(): void
    {
        // Havana springs forward at 00:00 on 2026-03-08, so that midnight is skipped.
        $freq = daily_frequency([], 'America/Havana', '2026-03-05', '2026-03-12');
        $this->assertSame(8, count($freq));
        $this->assertSame('2026-03-12', array_key_last($freq));
    }

    public function testHeatmapKeepsLastDayWhenMidnightDoesNotExist(): void
    {
        $weeks = build_heatmap([], 'America/Havana', '2026-03-05', '2026-03-12');
        $dates = array_column(array_filter(array_merge(...$weeks)), 'date');
        $this->assertSame(8, count($dates));
        $this->assertSame('2026-03-12', end($dates));
    }

    public function testLastNDaysRangeEndsToday(): void
    {
        $this->assertSame(
            ['start' => '2026-03-02', 'end' => '2026-05-30'],
            last_n_days_range(90, '2026-05-30')
        );
    }

    public function testStatsPresetDetectsLastNDays(): void
    {
        $range = last_n_days_range(90, '2026-05-30');
        $this->assertSame('last:90', stats_range_preset($range, '2026-05-30', '2025-01-01', [30, 60, 90]));
    }

    public function testStatsPresetDetectsAllTime(): void
    {
        $range = ['start' => '2025-01-01', 'end' => '2026-05-30'];
        $this->assertSame('all', stats_range_preset($range, '2026-05-30', '2025-01-01', [30, 60, 90]));
    }

    public function testStatsPresetIsNullForCustomRange(): void
    {
        $range = ['start' => '2026-04-01', 'end' => '2026-05-10'];
        $this->assertNull(stats_range_preset($range, '2026-05-30', '2025-01-01', [30, 60, 90]));
    }

    // --- Base path stripping ---

    public function testStripBasePathRemovesPrefix(): void
    {
        $this->assertEquals('/setup', strip_base_path('/gistats/setup', '/gistats'));
    }

    public function testStripBasePathRootBecomesSlash(): void
    {
        $this->assertEquals('/', strip_base_path('/gistats', '/gistats'));
    }

    public function testStripBasePathWithEmptyBasePathReturnsUriUnchanged(): void
    {
        $this->assertEquals('/setup', strip_base_path('/setup', ''));
    }

    public function testStripBasePathWithNonMatchingPrefixReturnsUriUnchanged(): void
    {
        $this->assertEquals('/other/setup', strip_base_path('/other/setup', '/gistats'));
    }
}
