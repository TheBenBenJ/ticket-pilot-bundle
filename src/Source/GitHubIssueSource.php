<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Source;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBenBenJ\TicketPilotBundle\Contract\ReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\RecipeResult;
use TheBenBenJ\TicketPilotBundle\Review\ReviewSummary;

/**
 * Reads open issues from a GitHub repository (REST API v3).
 *
 * Pending issues are the open ones carrying a configurable label, oldest first.
 * Pull requests are skipped (the issues endpoint also returns them). The ticket
 * key is the issue number; an issue carrying the configured bug label is typed
 * as a bug so the branch planner routes it to the hotfix flow.
 */
final class GitHubIssueSource implements TicketSourceInterface, TicketReporterInterface, ReviewReporterInterface
{
    private const NAME = 'github';

    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    private readonly string $owner;
    private readonly string $repo;

    public function __construct(
        string $baseUri,
        string $token,
        string $repository,
        private readonly string $pendingLabel,
        private readonly string $bugLabel,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        [$this->owner, $this->repo] = $this->splitRepository($repository);
        $this->client = $httpClient->withOptions([
            'base_uri' => rtrim($baseUri, '/').'/',
            'auth_bearer' => $token,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function fetchPending(int $limit = 1): array
    {
        $query = [
            'state' => 'open',
            'sort' => 'created',
            'direction' => 'asc',
            'per_page' => max(1, $limit),
        ];
        if ('' !== $this->pendingLabel) {
            $query['labels'] = $this->pendingLabel;
        }

        try {
            $issues = $this->client->request(
                'GET',
                \sprintf('repos/%s/%s/issues', $this->owner, $this->repo),
                ['query' => $query],
            )->toArray();

            $tickets = [];
            foreach ($issues as $issue) {
                if (isset($issue['pull_request'])) {
                    continue; // the issues endpoint also returns pull requests
                }
                $tickets[] = $this->mapTicket($issue);
                if (\count($tickets) >= $limit) {
                    break;
                }
            }

            return $tickets;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('GitHubIssueSource::fetchPending failed: '.$e->getMessage());

            return [];
        }
    }

    public function fetchOne(string $key): Ticket
    {
        $number = preg_replace('/\D+/', '', $key) ?: $key;

        try {
            $issue = $this->client->request(
                'GET',
                \sprintf('repos/%s/%s/issues/%s', $this->owner, $this->repo, $number),
            )->toArray();

            return $this->mapTicket($issue, $this->fetchComments($number));
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('GitHubIssueSource::fetchOne(%s) failed: %s', $number, $e->getMessage()));

            throw new \RuntimeException(\sprintf('Unable to fetch GitHub issue %s', $number), 0, $e);
        }
    }

    public function reportMergeRequest(Ticket $ticket, MergeRequest $mergeRequest): void
    {
        $this->postComment($ticket->key, \sprintf('🤖 Pull request opened: %s', $mergeRequest->url));
    }

    public function reportReview(Ticket $ticket, RecipeResult $result): void
    {
        $this->postComment($ticket->key, ReviewSummary::plain($ticket, $result));
    }

    private function postComment(string $key, string $body): void
    {
        $number = preg_replace('/\D+/', '', $key) ?: $key;

        try {
            $this->client->request(
                'POST',
                \sprintf('repos/%s/%s/issues/%s/comments', $this->owner, $this->repo, $number),
                ['json' => ['body' => $body]],
            )->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('GitHubIssueSource::postComment(%s) failed: %s', $number, $e->getMessage()));
        }
    }

    /**
     * @return list<string>
     */
    private function fetchComments(string $number): array
    {
        try {
            $comments = $this->client->request(
                'GET',
                \sprintf('repos/%s/%s/issues/%s/comments', $this->owner, $this->repo, $number),
            )->toArray();

            return array_values(array_filter(array_map(
                static fn (array $c): string => \sprintf('[%s] %s', $c['user']['login'] ?? 'unknown', trim((string) ($c['body'] ?? ''))),
                $comments,
            )));
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('GitHubIssueSource::fetchComments(%s) failed: %s', $number, $e->getMessage()));

            return [];
        }
    }

    /**
     * @param array<string, mixed> $issue
     * @param list<string>         $comments
     */
    private function mapTicket(array $issue, array $comments = []): Ticket
    {
        $labels = array_values(array_filter(array_map(
            static fn (array $label): string => (string) ($label['name'] ?? ''),
            $issue['labels'] ?? [],
        )));

        $isBug = \in_array($this->bugLabel, $labels, true);

        return new Ticket(
            key: (string) $issue['number'],
            title: $issue['title'] ?? '',
            description: (string) ($issue['body'] ?? ''),
            type: $isBug ? 'Bug' : 'Task',
            source: self::NAME,
            comments: $comments,
            labels: $labels,
            assignee: $issue['assignee']['login'] ?? null,
            reporter: $issue['user']['login'] ?? null,
            url: $issue['html_url'] ?? null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRepository(string $repository): array
    {
        $parts = explode('/', trim($repository, '/'));
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException(\sprintf('GitHub repository must be "owner/repo", got "%s"', $repository));
        }

        return [$parts[0], $parts[1]];
    }
}
