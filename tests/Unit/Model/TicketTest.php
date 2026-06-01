<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

final class TicketTest extends TestCase
{
    public function testIsBugMatchesConfiguredTypesCaseInsensitively(): void
    {
        self::assertTrue($this->ticket(type: 'Bug')->isBug());
        self::assertTrue($this->ticket(type: 'ANOMALIE')->isBug());
        self::assertFalse($this->ticket(type: 'Story')->isBug());
        self::assertTrue($this->ticket(type: 'Regression')->isBug(['regression']));
    }

    public function testFixVersionReturnsFirstOrNull(): void
    {
        self::assertSame('1.2', $this->ticket(fixVersions: ['1.2', '1.3'])->fixVersion());
        self::assertNull($this->ticket()->fixVersion());
    }

    public function testIsFromSource(): void
    {
        self::assertTrue($this->ticket(source: 'sentry')->isFromSource('sentry'));
        self::assertFalse($this->ticket(source: 'jira')->isFromSource('sentry'));
    }

    public function testToArrayExposesEverySemanticField(): void
    {
        $array = $this->ticket(type: 'Bug', fixVersions: ['1.0'])->toArray();

        self::assertSame('PROJ-1', $array['key']);
        self::assertSame(['1.0'], $array['fix_versions']);
        self::assertArrayHasKey('acceptance_criteria', $array);
        self::assertArrayHasKey('linked_tickets', $array);
    }

    /**
     * @param list<string> $fixVersions
     */
    private function ticket(
        string $type = 'Task',
        string $source = 'jira',
        array $fixVersions = [],
    ): Ticket {
        return new Ticket(
            key: 'PROJ-1',
            title: 'Title',
            description: 'Description',
            type: $type,
            source: $source,
            fixVersions: $fixVersions,
        );
    }
}
