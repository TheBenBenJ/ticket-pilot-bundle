<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Run\JsonlRunStore;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;

final class JsonlRunStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/tpb-runs-'.bin2hex(random_bytes(4)).'/runs.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            @rmdir(\dirname($this->path));
        }
    }

    public function testRecentReturnsEmptyWhenNothingRecorded(): void
    {
        self::assertSame([], (new JsonlRunStore($this->path))->recent());
    }

    public function testRecordThenReadBackNewestFirst(): void
    {
        $store = new JsonlRunStore($this->path);
        $store->record(new RunRecord('1', 'auto-dev', 'P-1', 'success', '2026-01-01T10:00:00+00:00'));
        $store->record(new RunRecord('2', 'review', 'P-2', 'passed', '2026-01-01T11:00:00+00:00'));

        $recent = $store->recent();

        self::assertCount(2, $recent);
        self::assertSame('2', $recent[0]->id);
        self::assertSame('1', $recent[1]->id);
        self::assertSame('passed', $recent[0]->status);
    }

    public function testRecentHonoursTheLimit(): void
    {
        $store = new JsonlRunStore($this->path);
        for ($i = 1; $i <= 5; ++$i) {
            $store->record(new RunRecord((string) $i, 'auto-dev', 'P-'.$i, 'success', '2026-01-01T10:0'.$i.':00+00:00'));
        }

        $recent = $store->recent(2);

        self::assertCount(2, $recent);
        self::assertSame('5', $recent[0]->id);
        self::assertSame('4', $recent[1]->id);
    }

    public function testCorruptLinesAreSkipped(): void
    {
        @mkdir(\dirname($this->path), 0o777, true);
        file_put_contents($this->path, "not json\n".json_encode((new RunRecord('ok', 'review', 'P-9', 'failed', '2026-01-01T10:00:00+00:00'))->toArray())."\n");

        $recent = (new JsonlRunStore($this->path))->recent();

        self::assertCount(1, $recent);
        self::assertSame('ok', $recent[0]->id);
    }
}
