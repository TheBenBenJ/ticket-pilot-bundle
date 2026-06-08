<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Optional capability for {@see TicketSourceInterface} implementations that can
 * move a ticket to a configured workflow status after a pipeline step.
 */
interface TicketLifecycleReporterInterface
{
    /**
     * Moves the ticket to the configured post-merge-request status (no-op when unset).
     */
    public function onMergeRequestOpened(Ticket $ticket): void;

    /**
     * Moves the ticket after a browser review verdict.
     */
    public function onReviewFinished(Ticket $ticket, bool $passed, bool $inconclusive): void;

    /**
     * Moves the ticket after an iteration run completed.
     */
    public function onIterateFinished(Ticket $ticket): void;
}
