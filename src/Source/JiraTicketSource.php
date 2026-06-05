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
 * Reads tickets from a Jira Cloud instance (REST API v3).
 *
 * Pending tickets are selected by JQL: a configurable label at a configurable
 * status, ordered by priority then creation date.
 */
final class JiraTicketSource implements TicketSourceInterface, TicketReporterInterface, ReviewReporterInterface
{
    private const NAME = 'jira';

    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    private readonly string $baseUri;

    public function __construct(
        string $baseUri,
        private readonly string $email,
        private readonly string $token,
        private readonly string $project,
        private readonly string $pendingLabel,
        private readonly string $pendingStatus,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->baseUri = rtrim($baseUri, '/').'/';
        $this->client = $httpClient->withOptions([
            'base_uri' => $this->baseUri,
            'auth_basic' => [$this->email, $this->token],
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
        $jql = \sprintf(
            'project = %s AND labels = %s AND status = "%s" ORDER BY priority DESC, created ASC',
            $this->project,
            $this->pendingLabel,
            $this->pendingStatus,
        );

        try {
            $data = $this->client->request('GET', 'rest/api/3/search/jql', [
                'query' => [
                    'jql' => $jql,
                    'maxResults' => $limit,
                    'fields' => 'summary,issuetype,priority,labels,fixVersions,components,subtasks,issuelinks,comment,assignee,reporter,status',
                ],
            ])->toArray();

            $tickets = [];
            foreach ($data['issues'] ?? [] as $issue) {
                $tickets[] = $this->fetchOne($issue['key']);
            }

            return $tickets;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('JiraTicketSource::fetchPending failed: '.$e->getMessage());

            return [];
        }
    }

    public function fetchOne(string $key): Ticket
    {
        try {
            $data = $this->client->request('GET', \sprintf('rest/api/3/issue/%s', $key), [
                'query' => [
                    'expand' => 'renderedFields,names',
                    'fields' => 'summary,description,issuetype,priority,labels,fixVersions,components,subtasks,issuelinks,comment,assignee,reporter,customfield_10020,status',
                ],
            ])->toArray();

            return $this->mapTicket($data);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('JiraTicketSource::fetchOne(%s) failed: %s', $key, $e->getMessage()));

            throw new \RuntimeException(\sprintf('Unable to fetch Jira ticket %s', $key), 0, $e);
        }
    }

    public function reportMergeRequest(Ticket $ticket, MergeRequest $mergeRequest): void
    {
        $this->postComment($ticket->key, \sprintf('🤖 Merge request opened: %s', $mergeRequest->url));
    }

    public function reportReview(Ticket $ticket, RecipeResult $result): void
    {
        $this->postComment($ticket->key, ReviewSummary::plain($ticket, $result));
    }

    /**
     * Posts a comment, turning each text line into an ADF paragraph (Jira v3 requires ADF).
     */
    private function postComment(string $key, string $text): void
    {
        $paragraphs = array_map(
            static fn (string $line): array => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => '' !== $line ? $line : ' ']],
            ],
            explode("\n", $text),
        );

        try {
            $this->client->request('POST', \sprintf('rest/api/3/issue/%s/comment', $key), [
                'json' => ['body' => ['type' => 'doc', 'version' => 1, 'content' => $paragraphs]],
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('JiraTicketSource::postComment(%s) failed: %s', $key, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapTicket(array $data): Ticket
    {
        $fields = $data['fields'] ?? [];
        $rendered = $data['renderedFields'] ?? [];

        $description = $this->textFromAdf($fields['description'] ?? null);
        if (!empty($rendered['description'])) {
            $description = html_entity_decode(strip_tags($rendered['description']), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }

        $comments = [];
        foreach ($fields['comment']['comments'] ?? [] as $comment) {
            $body = $this->textFromAdf($comment['body'] ?? null);
            if ('' !== $body) {
                $comments[] = \sprintf('[%s] %s', $comment['author']['displayName'] ?? 'Unknown', $body);
            }
        }

        $linked = [];
        foreach ($fields['issuelinks'] ?? [] as $link) {
            $linked[] = $link['outwardIssue']['key'] ?? $link['inwardIssue']['key'] ?? null;
        }

        return new Ticket(
            key: $data['key'],
            title: $fields['summary'] ?? '',
            description: $description,
            type: $fields['issuetype']['name'] ?? 'Task',
            source: self::NAME,
            fixVersions: array_values(array_filter(array_map(
                static fn (array $v): string => $v['name'] ?? '',
                $fields['fixVersions'] ?? [],
            ))),
            acceptanceCriteria: $this->extractAcceptanceCriteria($description),
            comments: $comments,
            subTasks: array_values(array_filter(array_map(
                static fn (array $s): string => $s['key'] ?? '',
                $fields['subtasks'] ?? [],
            ))),
            priority: $fields['priority']['name'] ?? 'Medium',
            components: array_values(array_filter(array_map(
                static fn (array $c): string => $c['name'] ?? '',
                $fields['components'] ?? [],
            ))),
            labels: $fields['labels'] ?? [],
            linkedTickets: array_values(array_filter($linked)),
            sprint: $this->activeSprintName($fields['customfield_10020'] ?? null),
            assignee: $fields['assignee']['displayName'] ?? null,
            reporter: $fields['reporter']['displayName'] ?? null,
            url: \sprintf('%sbrowse/%s', $this->baseUri, $data['key']),
        );
    }

    /**
     * Extracts plain text from an Atlassian Document Format (ADF) node tree.
     *
     * @param array<string, mixed>|null $adf
     */
    private function textFromAdf(?array $adf): string
    {
        if (null === $adf || !isset($adf['content'])) {
            return '';
        }

        $text = '';
        foreach ($adf['content'] as $node) {
            $text .= $this->textFromNode($node);
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textFromNode(array $node): string
    {
        $text = $node['text'] ?? '';

        if (isset($node['content']) && \is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->textFromNode($child);
            }
        }

        if (\in_array($node['type'] ?? '', ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote'], true)) {
            $text .= "\n";
        }

        return $text;
    }

    private function extractAcceptanceCriteria(string $description): string
    {
        $patterns = [
            '/crit[èe]res?\s+d[\'\x{2019}]acceptation\s*[:\-]?\s*(.*?)(?=\n\n|\z)/isu',
            '/acceptance\s+criteria\s*[:\-]?\s*(.*?)(?=\n\n|\z)/isu',
            '/definition\s+of\s+done\s*[:\-]?\s*(.*?)(?=\n\n|\z)/isu',
        ];

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $description, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function activeSprintName(mixed $sprints): ?string
    {
        if (!\is_array($sprints)) {
            return null;
        }

        foreach ($sprints as $sprint) {
            if (\is_array($sprint) && 'active' === ($sprint['state'] ?? '')) {
                return $sprint['name'] ?? null;
            }
        }

        return null;
    }
}
