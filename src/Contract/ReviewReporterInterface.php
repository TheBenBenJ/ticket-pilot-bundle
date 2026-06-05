<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\RecipeResult;

/**
 * Optional capability a {@see TicketSourceInterface} may implement to report the
 * browser-review outcome back to the tracker (test results + screenshots).
 * Best-effort: a failure here never fails the review.
 */
interface ReviewReporterInterface
{
    public function reportReview(Ticket $ticket, RecipeResult $result): void;
}
