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
        private readonly string $screenshotsDir = '',
        private readonly string $screenshotsBaseUrl = '',
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

        if (\is_array($files) && [] !== $files && '' !== $this->screenshotsDir) {
            $data['screenshots'] = $this->saveScreenshots($files, (string) ($data['id'] ?? bin2hex(random_bytes(6))));
        }

        $this->store->record(RunRecord::fromArray($data));

        return new JsonResponse(['status' => 'recorded'], 201);
    }

    /**
     * Saves the base64 screenshots under <screenshots_dir>/<runId>/ and returns
     * their public URLs.
     *
     * @param array<mixed> $files
     *
     * @return list<string>
     */
    private function saveScreenshots(array $files, string $runId): array
    {
        $runId = preg_replace('/[^A-Za-z0-9_-]/', '', $runId) ?: 'run';
        $dir = rtrim($this->screenshotsDir, '/').'/'.$runId;
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return [];
        }

        $urls = [];
        foreach ($files as $file) {
            if (!\is_array($file) || !isset($file['name'], $file['data'])) {
                continue;
            }
            // Keep only the base name to avoid path traversal.
            $name = basename((string) $file['name']);
            $raw = base64_decode((string) $file['data'], true);
            if (false === $raw || '' === $name) {
                continue;
            }
            if (false !== file_put_contents($dir.'/'.$name, $raw)) {
                $urls[] = rtrim($this->screenshotsBaseUrl, '/').'/'.rawurlencode($runId).'/'.rawurlencode($name);
            }
        }

        return $urls;
    }
}
