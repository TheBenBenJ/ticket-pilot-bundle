<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunScreenshotPersister;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;
use TheBenBenJ\TicketPilotBundle\Run\TrackedRunStore;

final class TrackedRunStoreTest extends TestCase
{
    public function testPersistsScreenshotsBeforeRecordingLocally(): void
    {
        $dir = sys_get_temp_dir().'/tpb-tracked-'.bin2hex(random_bytes(4));
        $png = $dir.'/shot.png';
        mkdir($dir);
        file_put_contents($png, 'BYTES');

        $inner = new CapturingStore();
        $store = new TrackedRunStore($inner, new RunScreenshotPersister($dir.'/public', '/ticket-pilot/screenshots'));

        $store->record(new RunRecord('run42', 'review', 'P-1', 'passed', '2026-01-01T10:00:00+00:00', '', '', '', '', '', 0.0, [$png]));

        self::assertCount(1, $inner->records);
        self::assertStringStartsWith('/ticket-pilot/screenshots/run42/', $inner->records[0]->screenshots[0]);

        @unlink($dir.'/public/run42/shot.png');
        @rmdir($dir.'/public/run42');
        @rmdir($dir.'/public');
        unlink($png);
        rmdir($dir);
    }
}

final class CapturingStore implements RunStoreInterface
{
    /** @var list<RunRecord> */
    public array $records = [];

    public function record(RunRecord $record): void
    {
        $this->records[] = $record;
    }

    public function recent(int $limit = 50): array
    {
        return $this->records;
    }
}
