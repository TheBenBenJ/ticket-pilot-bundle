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
        $payload['_files'] = $this->filesFromScreenshots($record->screenshots);
        // Bare names as fallback when the dashboard host cannot persist files.
        $payload['screenshots'] = array_values(array_map(
            static function (string $shot): string {
                if (str_starts_with($shot, 'http') || (str_starts_with($shot, '/') && !is_file($shot))) {
                    return basename(parse_url($shot, \PHP_URL_PATH) ?: $shot);
                }

                return basename($shot);
            },
            array_filter($record->screenshots, static fn (string $s): bool => '' !== $s),
        ));

        try {
            $this->client->request('POST', $this->url, [
                'headers' => ['X-Ticket-Pilot-Token' => $this->token],
                'json' => $payload,
                'timeout' => 120,
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

    /**
     * @param list<string> $screenshots
     *
     * @return list<array{name: string, data: string}>
     */
    private function filesFromScreenshots(array $screenshots): array
    {
        $files = [];
        foreach ($screenshots as $shot) {
            if (is_file($shot)) {
                $raw = file_get_contents($shot);
                if (false === $raw) {
                    continue;
                }
                $files[] = [
                    'name' => basename($shot),
                    'data' => base64_encode($raw),
                ];
                continue;
            }

            if (!str_starts_with($shot, 'data:')) {
                continue;
            }

            if (1 !== preg_match('#^data:([^;,]+)?(?:;base64)?,(.+)$#s', $shot, $m)) {
                continue;
            }

            $raw = base64_decode($m[2], true);
            if (false === $raw) {
                continue;
            }

            $ext = match (strtolower((string) $m[1])) {
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'png',
            };

            $files[] = [
                'name' => \sprintf('screenshot-%d.%s', \count($files) + 1, $ext),
                'data' => base64_encode($raw),
            ];
        }

        return $files;
    }
}
