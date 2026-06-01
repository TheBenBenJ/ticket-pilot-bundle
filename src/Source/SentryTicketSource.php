<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Source;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Reads unresolved issues from Sentry and exposes them as bug tickets, enriched
 * with the latest event stacktrace (vendor frames stripped).
 *
 * Ticket keys are prefixed with "SENTRY-"; {@see self::fetchOne()} accepts the
 * raw numeric id with or without that prefix.
 */
final class SentryTicketSource implements TicketSourceInterface
{
    private const NAME = 'sentry';
    private const KEY_PREFIX = 'SENTRY-';

    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    private readonly string $baseUri;

    public function __construct(
        string $baseUri,
        private readonly string $token,
        private readonly string $organization,
        private readonly string $project,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->baseUri = rtrim($baseUri, '/').'/';
        $this->client = $httpClient->withOptions([
            'base_uri' => $this->baseUri,
            'auth_bearer' => $this->token,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function fetchPending(int $limit = 1): array
    {
        try {
            $issues = $this->client->request(
                'GET',
                \sprintf('api/0/projects/%s/%s/issues/', $this->organization, $this->project),
                ['query' => ['query' => 'is:unresolved', 'statsPeriod' => '14d']],
            )->toArray();

            return array_map(
                fn (array $issue): Ticket => $this->mapTicket($issue),
                \array_slice($issues, 0, $limit),
            );
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('SentryTicketSource::fetchPending failed: '.$e->getMessage());

            return [];
        }
    }

    public function fetchOne(string $key): Ticket
    {
        $issueId = str_starts_with($key, self::KEY_PREFIX) ? substr($key, \strlen(self::KEY_PREFIX)) : $key;

        try {
            $issue = $this->client->request(
                'GET',
                \sprintf('api/0/organizations/%s/issues/%s/', $this->organization, $issueId),
            )->toArray();

            return $this->mapTicket($issue, $this->fetchStacktrace($issueId));
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('SentryTicketSource::fetchOne(%s) failed: %s', $issueId, $e->getMessage()));

            throw new \RuntimeException(\sprintf('Unable to fetch Sentry issue %s', $issueId), 0, $e);
        }
    }

    private function fetchStacktrace(string $issueId): string
    {
        try {
            $event = $this->client->request(
                'GET',
                \sprintf('api/0/organizations/%s/issues/%s/events/latest/', $this->organization, $issueId),
            )->toArray();

            return $this->extractStacktrace($event);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('SentryTicketSource::fetchStacktrace(%s) failed: %s', $issueId, $e->getMessage()));

            return '';
        }
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function mapTicket(array $issue, string $stacktrace = ''): Ticket
    {
        $issueId = (string) ($issue['id'] ?? '');
        $title = $issue['title'] ?? 'Unknown Sentry issue';
        $culprit = $issue['culprit'] ?? '';
        $level = $issue['level'] ?? 'error';
        $events = $issue['count'] ?? '0';
        $users = $issue['userCount'] ?? '0';

        $description = \sprintf("## Sentry issue #%s\n\n**Error**: %s", $issueId, $title);
        if ('' !== $culprit) {
            $description .= \sprintf("\n**Location**: %s", $culprit);
        }
        $description .= \sprintf("\n**Level**: %s\n**Occurrences**: %s events, %s users affected", $level, $events, $users);
        if (!empty($issue['firstSeen'])) {
            $description .= \sprintf("\n**First seen**: %s", $issue['firstSeen']);
        }
        if (!empty($issue['lastSeen'])) {
            $description .= \sprintf("\n**Last seen**: %s", $issue['lastSeen']);
        }
        if ('' !== $stacktrace) {
            $description .= \sprintf("\n\n## Stacktrace (latest event)\n```\n%s\n```", $stacktrace);
        }

        $comments = [];
        $metadata = $issue['metadata'] ?? [];
        if (!empty($metadata['value'])) {
            $comments[] = \sprintf('Message: %s', $metadata['value']);
        }
        if (!empty($metadata['filename'])) {
            $comments[] = \sprintf('File: %s', $metadata['filename']);
        }

        return new Ticket(
            key: self::KEY_PREFIX.$issueId,
            title: $title,
            description: $description,
            type: 'Bug',
            source: self::NAME,
            comments: $comments,
            priority: $this->mapPriority($level, (int) $events),
            components: '' !== $culprit ? [$culprit] : [],
            labels: [$level],
            url: \sprintf('%sorganizations/%s/issues/%s/', $this->baseUri, $this->organization, $issueId),
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractStacktrace(array $event): string
    {
        $lines = [];

        foreach ($event['entries'] ?? [] as $entry) {
            if ('exception' !== ($entry['type'] ?? '')) {
                continue;
            }

            foreach ($entry['data']['values'] ?? [] as $exception) {
                $lines[] = \sprintf('%s: %s', $exception['type'] ?? 'Exception', $exception['value'] ?? '');

                foreach ($exception['stacktrace']['frames'] ?? [] as $frame) {
                    $file = $frame['filename'] ?? $frame['absPath'] ?? '?';
                    if (str_contains((string) $file, 'vendor/')) {
                        continue;
                    }

                    $lines[] = \sprintf('  at %s() in %s:%s', $frame['function'] ?? '?', $file, $frame['lineNo'] ?? '?');

                    foreach ($frame['context'] ?? [] as [$lineNo, $code]) {
                        if ($lineNo === ($frame['lineNo'] ?? null)) {
                            $lines[] = \sprintf('    > %s', $code);
                        }
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function mapPriority(string $level, int $events): string
    {
        return match (true) {
            $events > 100 || 'fatal' === $level => 'Highest',
            $events > 50 || 'error' === $level => 'High',
            'warning' === $level => 'Medium',
            default => 'Low',
        };
    }
}
