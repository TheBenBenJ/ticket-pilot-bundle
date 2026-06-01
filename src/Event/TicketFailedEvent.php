<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Event;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Dispatched when processing a ticket failed (after any branch cleanup, before
 * the error is rethrown). Listen to it to alert, log or open a follow-up.
 */
final class TicketFailedEvent
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly \Throwable $error,
    ) {
    }
}
