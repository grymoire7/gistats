<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bin/legacy_merge.php';

class LegacyMergeTest extends TestCase
{
    private const TZ = 'America/Chicago';

    private function bm(string ...$blocks): string
    {
        return implode("\n\n", $blocks) . "\n\n";
    }

    public function testParsesBmBlockWithDurationAndLocalTime(): void
    {
        $txt = $this->bm("Time: 2026-10-17 01:45p Saturday\nType: 5. Lackng fiber (Chicken nuggets)\nDuration: 02:01");
        [$rows, $errors] = parse_bm_export($txt, self::TZ);
        $this->assertSame([], $errors);
        $this->assertSame([[
            'occurred_at'      => '2026-10-17T18:45:00Z',
            'stool_type'       => 5,
            'duration_seconds' => 121,
            'note'             => null,
            'urgency'          => 0,
        ]], $rows);
    }

    public function testParsesBmNoteAndMidnightNoon(): void
    {
        $txt = $this->bm(
            "Time: 2025-06-10 12:05a Tuesday\nType: 4. Normal\nDuration: 05:00\nNote: no blood\n",
            "Time: 2025-06-10 12:15p Tuesday\nType: 4. Normal\nDuration: 00:30"
        );
        [$rows] = parse_bm_export($txt, self::TZ);
        $this->assertSame('no blood', $rows[0]['note']);
        $this->assertSame('2025-06-10T05:05:00Z', $rows[0]['occurred_at']);
        $this->assertSame('2025-06-10T17:15:00Z', $rows[1]['occurred_at']);
    }

    public function testBadBmBlockReportsErrorAndContinues(): void
    {
        $txt = $this->bm(
            "Time: nonsense\nType: 4. Normal\nDuration: 01:00",
            "Time: 2026-10-17 01:45p Saturday\nType: 9. Bogus\nDuration: 01:00",
            "Time: 2026-10-17 02:45p Saturday\nType: 3. Ok\nDuration: 01:00"
        );
        [$rows, $errors] = parse_bm_export($txt, self::TZ);
        $this->assertCount(1, $rows);
        $this->assertCount(2, $errors);
    }

    public function testMergeDropsPoopifyOnDatesBmCoversAndKeepsOthers(): void
    {
        $bm = [['occurred_at' => '2026-10-17T14:00:00Z'] + $this->row()];
        $poopify = [
            ['occurred_at' => '2026-10-17T14:01:00Z'] + $this->row(), // same local day, near-miss
            ['occurred_at' => '2026-08-07T14:00:00Z'] + $this->row(), // BM has nothing that day
        ];
        $merged = merge_legacy($bm, $poopify, self::TZ);
        $this->assertCount(2, $merged['rows']);
        $this->assertCount(1, $merged['dropped']);
        $this->assertSame('2026-10-17T14:01:00Z', $merged['dropped'][0]['occurred_at']);
        $this->assertSame(['2026-08-07T14:00:00Z'], array_column($merged['kept_poopify'], 'occurred_at'));
    }

    public function testMergeGroupsByLocalDateNotUtcDate(): void
    {
        // 23:30 Chicago on Oct 17 is 04:30Z on Oct 18
        $bm = [['occurred_at' => '2026-10-18T04:30:00Z'] + $this->row()];
        $poopify = [['occurred_at' => '2026-10-17T15:00:00Z'] + $this->row()]; // Oct 17 local
        $merged = merge_legacy($bm, $poopify, self::TZ);
        $this->assertCount(1, $merged['dropped']);
    }

    public function testWritesNativeCsvTheImporterAccepts(): void
    {
        $csv = native_csv([
            ['occurred_at' => '2026-10-17T18:45:00Z', 'stool_type' => 5, 'duration_seconds' => 121, 'note' => null, 'urgency' => 0],
        ]);
        $this->assertStringStartsWith("occurred_at_utc,duration_seconds,stool_type,note,urgency\n", $csv);
        $this->assertStringContainsString("2026-10-17T18:45:00Z,121,5,,0\n", $csv);
    }

    private function row(): array
    {
        return ['stool_type' => 4, 'duration_seconds' => 60, 'note' => null, 'urgency' => 0];
    }
}
