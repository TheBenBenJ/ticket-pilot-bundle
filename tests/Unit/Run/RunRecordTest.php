<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;

final class RunRecordTest extends TestCase
{
    public function testToArrayFromArrayRoundtrip(): void
    {
        $record = new RunRecord('id1', 'iterate', 'P-1', 'success', '2026-01-01T10:00:00+00:00', 'feat/P-1', 'did things', 'http://mr/1', 'cursor', 'jira', 12.5);

        $restored = RunRecord::fromArray($record->toArray());

        self::assertEquals($record, $restored);
    }

    public function testCreateGeneratesAnIdAndTimestamp(): void
    {
        $record = RunRecord::create(RunRecord::TYPE_REVIEW, 'P-2', RunRecord::STATUS_PASSED);

        self::assertNotSame('', $record->id);
        self::assertSame('review', $record->type);
        self::assertSame('passed', $record->status);
        self::assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable($record->startedAt));
    }

    public function testFromArrayToleratesMissingKeys(): void
    {
        $record = RunRecord::fromArray(['type' => 'auto-dev', 'ticketKey' => 'P-3']);

        self::assertSame('auto-dev', $record->type);
        self::assertSame('P-3', $record->ticketKey);
        self::assertSame('', $record->branch);
        self::assertSame(0.0, $record->duration);
    }
}
