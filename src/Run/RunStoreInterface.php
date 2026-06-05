<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Persists and reads back the runs the bundle has launched.
 *
 * The default implementation is an append-only JSONL file, but a project may
 * provide its own (database, shared cache, …) by aliasing this interface.
 */
interface RunStoreInterface
{
    public function record(RunRecord $record): void;

    /**
     * Most recent runs first.
     *
     * @return list<RunRecord>
     */
    public function recent(int $limit = 50): array;
}
