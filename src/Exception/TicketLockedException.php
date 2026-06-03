<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Exception;

/**
 * Thrown when another run already holds the lock for a ticket — prevents two
 * concurrent runs (batch / cron) from processing the same ticket twice.
 */
final class TicketLockedException extends \RuntimeException
{
    public function __construct(public readonly string $ticketKey)
    {
        parent::__construct(\sprintf('Ticket "%s" is already being processed by another run.', $ticketKey));
    }
}
