<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;

final class MergeRequestFactoryTest extends TestCase
{
    public function testCommitMessageExpandsPlaceholders(): void
    {
        $factory = new MergeRequestFactory(commitMessageTemplate: '[{key}] {title} #REVIEW');

        self::assertSame('[PROJ-1] Fix the bug #REVIEW', $factory->commitMessage($this->ticket()));
    }

    public function testTitleCombinesKeyAndTitle(): void
    {
        self::assertSame('PROJ-1 - Fix the bug', (new MergeRequestFactory())->title($this->ticket()));
    }

    public function testDescriptionIncludesMetadataAndIssueLink(): void
    {
        $description = (new MergeRequestFactory())->description($this->ticket());

        self::assertStringContainsString('### Description', $description);
        self::assertStringContainsString('**Type**: Bug', $description);
        self::assertStringContainsString('https://jira.example/browse/PROJ-1', $description);
    }

    public function testAgentSummaryIsExtractedBetweenMarkers(): void
    {
        $factory = new MergeRequestFactory('<<<MR_SUMMARY', 'MR_SUMMARY>>>');
        $output = "noise\n<<<MR_SUMMARY\n### Solution\n- did the thing\nMR_SUMMARY>>>\ntrailing noise";

        $description = $factory->description($this->ticket(), $output);

        self::assertStringContainsString('### Implementation details', $description);
        self::assertStringContainsString('- did the thing', $description);
        self::assertStringNotContainsString('trailing noise', $description);
    }

    public function testAgentChatterWithoutMarkersIsDropped(): void
    {
        $description = (new MergeRequestFactory())->description($this->ticket(), 'Great! All tests pass.');

        self::assertStringNotContainsString('Implementation details', $description);
        self::assertStringNotContainsString('Great!', $description);
    }

    private function ticket(): Ticket
    {
        return new Ticket(
            key: 'PROJ-1',
            title: 'Fix the bug',
            description: 'Long description paragraph.',
            type: 'Bug',
            source: 'jira',
            priority: 'High',
            url: 'https://jira.example/browse/PROJ-1',
        );
    }
}
