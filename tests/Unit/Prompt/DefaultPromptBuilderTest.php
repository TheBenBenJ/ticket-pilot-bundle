<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Prompt\DefaultPromptBuilder;

final class DefaultPromptBuilderTest extends TestCase
{
    public function testPromptCarriesTicketContextAndMarkers(): void
    {
        $builder = new DefaultPromptBuilder(
            language: 'français',
            qualityCommands: ['make check', 'make test'],
            summaryStartMarker: '<<<MR_SUMMARY',
            summaryEndMarker: 'MR_SUMMARY>>>',
        );

        $prompt = $builder->build($this->ticket());

        self::assertStringContainsString('PROJ-42', $prompt);
        self::assertStringContainsString('Implement the feature', $prompt);
        self::assertStringContainsString('## Acceptance criteria', $prompt);
        self::assertStringContainsString('français', $prompt);
        self::assertStringContainsString('`make check`', $prompt);
        self::assertStringContainsString('<<<MR_SUMMARY', $prompt);
        self::assertStringContainsString('MR_SUMMARY>>>', $prompt);
    }

    public function testExtraInstructionsAreInjected(): void
    {
        $builder = new DefaultPromptBuilder(extraInstructions: 'All code MUST be in French.');

        self::assertStringContainsString('All code MUST be in French.', $builder->build($this->ticket()));
    }

    private function ticket(): Ticket
    {
        return new Ticket(
            key: 'PROJ-42',
            title: 'Implement the feature',
            description: 'A description.',
            type: 'Story',
            source: 'jira',
            acceptanceCriteria: 'It works.',
        );
    }
}
