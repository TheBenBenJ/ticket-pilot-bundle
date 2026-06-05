<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewResult;

/**
 * Optional capability a {@see TicketSourceInterface} may implement to report the
 * outcome of an agent-driven browser review back to the tracker (verdict, the
 * agent's summary and, where supported, the screenshots uploaded as attachments).
 *
 * Best-effort: a failure here never fails the review.
 */
interface AgentReviewReporterInterface
{
    public function reportAgentReview(Ticket $ticket, AgentReviewResult $result): void;
}
