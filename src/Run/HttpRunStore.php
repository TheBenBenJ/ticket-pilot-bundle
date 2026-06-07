<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Run store that POSTs each record to a remote ingest endpoint
 * ({@see \TheBenBenJ\TicketPilotBundle\Controller\RunIngestController}) instead of
 * writing locally.
 *
 * Used in throw-away CI containers so their runs land on the single environment
 * that owns the canonical JSONL file. Reading is not supported here (the
 * dashboard reads the canonical file directly on that environment).
 */
final class HttpRunStore implements RunStoreInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $url,
        private readonly string $token,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(RunRecord $record): void
    {
        $payload = $record->toArray();

        // Attach the screenshots that exist as local files (base64 over JSON, so no
        // multipart/mime dependency). The ingest saves them and rewrites the record's
        // screenshots to public URLs; the wire payload stays transient.
        $files = [];
        $names = [];
        foreach ($record->screenshots as $shot) {
            $names[] = basename($shot);
            if (is_file($shot) && ($raw = @file_get_contents($shot)) !== false) {
                $files[] = ['name' => basename($shot), 'data' => base64_encode($raw)];
            }
        }
        $payload['screenshots'] = $names;
        if ([] !== $files) {
            $payload['_files'] = $files;
        }

        try {
            $this->client->request('POST', $this->url, [
                'headers' => ['X-Ticket-Pilot-Token' => $this->token],
                'json' => $payload,
                'timeout' => 30,
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            // Best-effort: forwarding a run must never break the pipeline.
            $this->logger->warning('Run ingest POST failed: '.$e->getMessage());
        }
    }

    public function recent(int $limit = 50): array
    {
        return [];
    }
}
