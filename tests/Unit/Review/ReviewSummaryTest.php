<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\RecipeResult;
use TheBenBenJ\TicketPilotBundle\Review\RecipeStep;
use TheBenBenJ\TicketPilotBundle\Review\ReviewSummary;
use TheBenBenJ\TicketPilotBundle\Review\StepResult;

final class ReviewSummaryTest extends TestCase
{
    public function testPassedSummary(): void
    {
        $result = new RecipeResult(true, [
            new StepResult(new RecipeStep(RecipeStep::VISIT, '/'), true),
            new StepResult(new RecipeStep(RecipeStep::ASSERT_SEE, null, 'OK'), true),
        ], ['/var/shots/LYSI-1-result.png']);

        $text = ReviewSummary::plain($this->ticket(), $result);

        self::assertStringContainsString('✅ Review passed for LYSI-1', $text);
        self::assertStringContainsString('✓ visit /', $text);
        self::assertStringContainsString('Screenshots: LYSI-1-result.png', $text);
    }

    public function testFailedSummaryIncludesTheMessage(): void
    {
        $result = new RecipeResult(false, [
            new StepResult(new RecipeStep(RecipeStep::VISIT, '/'), true),
            new StepResult(new RecipeStep(RecipeStep::ASSERT_SEE, null, 'Succès'), false, 'Expected to see "Succès"'),
        ]);

        $text = ReviewSummary::plain($this->ticket(), $result);

        self::assertStringContainsString('❌ Review failed for LYSI-1', $text);
        self::assertStringContainsString('✗ assert_see — Expected to see "Succès"', $text);
    }

    private function ticket(): Ticket
    {
        return new Ticket('LYSI-1', 'Title', 'desc', 'Bug', 'jira');
    }
}
