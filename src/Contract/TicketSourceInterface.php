<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * A provider of tickets to be developed (Jira, Sentry, GitHub issues, ...).
 *
 * Implementations are registered as services tagged "ticket_pilot.ticket_source"
 * and resolved by {@see \TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry} via
 * {@see self::getName()}.
 */
interface TicketSourceInterface
{
    /**
     * Unique, lower-case identifier used to select this source (e.g. "jira").
     */
    public function getName(): string;

    /**
     * Tickets that are ready to be picked up by the pipeline, most relevant first.
     *
     * @return list<Ticket>
     */
    public function fetchPending(int $limit = 1): array;

    /**
     * Full details for a single ticket identified by its source-specific key.
     *
     * @throws \RuntimeException when the ticket cannot be retrieved
     */
    public function fetchOne(string $key): Ticket;
}
