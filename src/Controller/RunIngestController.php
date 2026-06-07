<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

/**
 * Ingest endpoint: appends a run posted by a remote pipeline (via
 * {@see \TheBenBenJ\TicketPilotBundle\Run\HttpRunStore}) to the canonical store,
 * so the environment that serves the dashboard owns the single runs file.
 *
 * Guarded by a shared token (header X-Ticket-Pilot-Token). An empty configured
 * token disables ingestion (every request is rejected).
 */
final class RunIngestController
{
    public function __construct(
        private readonly RunStoreInterface $store,
        private readonly string $token,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if ('' === $this->token || !hash_equals($this->token, (string) $request->headers->get('X-Ticket-Pilot-Token', ''))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data) || !isset($data['type'], $data['ticketKey'])) {
            return new JsonResponse(['error' => 'Invalid run payload'], 400);
        }

        /* @var array<string, mixed> $data */
        $this->store->record(RunRecord::fromArray($data));

        return new JsonResponse(['status' => 'recorded'], 201);
    }
}
