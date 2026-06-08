<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunScenarioPersister;
use TheBenBenJ\TicketPilotBundle\Run\RunScreenshotPersister;
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
        private readonly RunScreenshotPersister $screenshotPersister,
        private readonly RunScenarioPersister $scenarioPersister,
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

        /** @var array<string, mixed> $data */
        $files = $data['_files'] ?? null;
        unset($data['_files']);

        $runId = (string) ($data['id'] ?? bin2hex(random_bytes(6)));
        $fallback = array_values(array_filter(array_map('strval', (array) ($data['screenshots'] ?? [])), static fn (string $s): bool => '' !== $s));

        $urls = $this->screenshotPersister->persist(
            $runId,
            $fallback,
            \is_array($files) ? $this->normalizeFiles($files) : null,
        );
        if ([] !== $urls) {
            $data['screenshots'] = $urls;
        }

        $scenario = (string) ($data['scenario'] ?? '');
        if ('' !== trim($scenario)) {
            $scenarioUrl = $this->scenarioPersister->persist((string) $data['ticketKey'], $scenario);
            if ('' !== $scenarioUrl) {
                $data['scenarioUrl'] = $scenarioUrl;
            }
        }

        $this->store->record(RunRecord::fromArray($data));

        return new JsonResponse(['status' => 'recorded'], 201);
    }

    /**
     * @param array<mixed> $files
     *
     * @return list<array{name: string, data: string}>
     */
    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (!\is_array($file) || !isset($file['name'], $file['data'])) {
                continue;
            }
            $raw = base64_decode((string) $file['data'], true);
            if (false === $raw) {
                continue;
            }
            $out[] = ['name' => (string) $file['name'], 'data' => $raw];
        }

        return $out;
    }
}
