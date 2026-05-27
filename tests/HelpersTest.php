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

    public function testDurationToSeconds(): void
    {
        $this->assertEquals(300,  duration_to_seconds('05:00'));
        $this->assertEquals(330,  duration_to_seconds('05:30'));
        $this->assertEquals(0,    duration_to_seconds('00:00'));
        $this->assertEquals(3600, duration_to_seconds('60:00'));
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

    public function testDailyFrequencyReturns30Days(): void
    {
        $freq = daily_frequency([], 'America/New_York', 30);
        $this->assertCount(30, $freq);
    }

    public function testDailyFrequencyCountsEntries(): void
    {
        $tz = 'America/New_York';
        $todayUtc = (new DateTime('now', new DateTimeZone($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $entries = [
            ['occurred_at' => $todayUtc],
            ['occurred_at' => $todayUtc],
        ];
        $freq = daily_frequency($entries, $tz, 30);
        $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
        $this->assertEquals(2, $freq[$today]);
    }
}
