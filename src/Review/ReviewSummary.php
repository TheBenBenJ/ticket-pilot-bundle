<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Formats a {@see RecipeResult} as a human-readable summary posted back to the
 * ticket (one line per step, plus the screenshot file names).
 */
final class ReviewSummary
{
    public static function plain(Ticket $ticket, RecipeResult $result): string
    {
        $lines = [\sprintf('🤖 %s for %s', $result->passed ? '✅ Review passed' : '❌ Review failed', $ticket->key)];

        foreach ($result->steps as $step) {
            $label = $step->step->action;
            if (null !== $step->step->target && '' !== $step->step->target) {
                $label .= ' '.$step->step->target;
            }
            $lines[] = \sprintf('%s %s%s', $step->passed ? '✓' : '✗', $label, '' !== $step->message ? ' — '.$step->message : '');
        }

        if ([] !== $result->screenshots) {
            $lines[] = 'Screenshots: '.implode(', ', array_map('basename', $result->screenshots));
        }

        return implode("\n", $lines);
    }
}
