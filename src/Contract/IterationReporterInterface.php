<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Optional capability a {@see TicketSourceInterface} may implement to report that
 * the agent iterated on a ticket's branch in response to feedback (e.g. post a
 * comment summarising what was changed). Best-effort: a failure here never fails
 * the run.
 */
interface IterationReporterInterface
{
    public function reportIteration(Ticket $ticket, string $branch, string $summary): void;
}
