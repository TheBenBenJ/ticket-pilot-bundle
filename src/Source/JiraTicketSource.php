<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Source;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBenBenJ\TicketPilotBundle\Contract\AgentReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\AttachmentDownloaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\IterationReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\ReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketLifecycleReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Model\Attachment;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewResult;
use TheBenBenJ\TicketPilotBundle\Review\RecipeResult;
use TheBenBenJ\TicketPilotBundle\Review\ReviewSummary;

/**
 * Reads tickets from a Jira Cloud instance (REST API v3).
 *
 * Pending tickets are selected by JQL: a configurable label at a configurable
 * status, ordered by priority then creation date.
 */
final class JiraTicketSource implements TicketSourceInterface, TicketReporterInterface, TicketLifecycleReporterInterface, ReviewReporterInterface, AgentReviewReporterInterface, AttachmentDownloaderInterface, IterationReporterInterface
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
        private readonly string $statusAfterMergeRequest = '',
        private readonly string $statusAfterReviewPassed = '',
        private readonly string $statusAfterReviewFailed = '',
        private readonly string $statusAfterReviewInconclusive = '',
        private readonly string $statusAfterIterate = '',
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
                    'fields' => 'summary,description,issuetype,priority,labels,fixVersions,components,subtasks,issuelinks,comment,assignee,reporter,customfield_10020,status,attachment',
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
        $this->onMergeRequestOpened($ticket);
    }

    public function onMergeRequestOpened(Ticket $ticket): void
    {
        $this->transitionToStatus($ticket->key, $this->statusAfterMergeRequest);
    }

    public function onReviewFinished(Ticket $ticket, bool $passed, bool $inconclusive): void
    {
        if ($passed) {
            $this->transitionToStatus($ticket->key, $this->statusAfterReviewPassed);

            return;
        }

        $status = $inconclusive && '' !== $this->statusAfterReviewInconclusive
            ? $this->statusAfterReviewInconclusive
            : $this->statusAfterReviewFailed;
        $this->transitionToStatus($ticket->key, $status);
    }

    public function onIterateFinished(Ticket $ticket): void
    {
        $this->transitionToStatus($ticket->key, $this->statusAfterIterate);
    }

    public function reportIteration(Ticket $ticket, string $branch, string $summary): void
    {
        $text = \sprintf("🤖 Iterated on %s after feedback:\n%s", $branch, '' !== trim($summary) ? $summary : '(no summary)');
        $this->postComment($ticket->key, $text);
        $this->onIterateFinished($ticket);
    }

    public function reportReview(Ticket $ticket, RecipeResult $result): void
    {
        $this->postComment($ticket->key, ReviewSummary::plain($ticket, $result));
        $this->onReviewFinished($ticket, $result->passed, false);
    }

    public function reportAgentReview(Ticket $ticket, AgentReviewResult $result): void
    {
        // Upload the consolidated PDF report first, then the screenshots, so the
        // comment can reference them as attachments.
        if (null !== $result->reportPdf) {
            $this->uploadAttachment($ticket->key, $result->reportPdf);
        }
        foreach ($result->screenshots as $screenshot) {
            $this->uploadAttachment($ticket->key, $screenshot);
        }

        $inconclusive = str_contains(mb_strtoupper($result->summary), AgentReviewPromptBuilder::INCONCLUSIVE_TOKEN);
        $header = \sprintf(
            '🤖 Agent review %s — %s',
            $result->passed ? '✅ PASSED' : ($inconclusive ? '⚠️ INCONCLUSIVE' : '❌ FAILED'),
            $ticket->key,
        );
        $this->postComment($ticket->key, $header."\n\n".$result->summary);
        $this->onReviewFinished($ticket, $result->passed, $inconclusive);
    }

    /**
     * Uploads a file as a Jira issue attachment (multipart/form-data built by hand
     * so no extra dependency is required); the X-Atlassian-Token header is mandatory.
     */
    private function uploadAttachment(string $key, string $path): void
    {
        if ('' === $path || !is_file($path)) {
            return;
        }

        $name = basename($path);
        $boundary = '----TicketPilot'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\n"
            ."Content-Type: application/octet-stream\r\n\r\n"
            .(string) file_get_contents($path)."\r\n"
            ."--{$boundary}--\r\n";

        try {
            $this->client->request('POST', \sprintf('rest/api/3/issue/%s/attachments', $key), [
                'headers' => [
                    'X-Atlassian-Token' => 'no-check',
                    'Content-Type' => 'multipart/form-data; boundary='.$boundary,
                ],
                'body' => $body,
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('JiraTicketSource::uploadAttachment(%s) failed for "%s": %s', $key, $name, $e->getMessage()));
        }
    }

    public function downloadAttachments(Ticket $ticket, string $targetDir): array
    {
        if ([] === $ticket->attachments) {
            return [];
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0o777, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(\sprintf('Cannot create attachments directory "%s"', $targetDir));
        }

        $paths = [];
        foreach ($ticket->attachments as $attachment) {
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($attachment->filename)) ?: 'attachment';
            $dest = rtrim($targetDir, '/').'/'.$name;

            try {
                $content = $this->client->request('GET', $attachment->url)->getContent();
                file_put_contents($dest, $content);
                $paths[] = $dest;
            } catch (HttpExceptionInterface $e) {
                $this->logger->warning(\sprintf('JiraTicketSource::downloadAttachments(%s) failed for "%s": %s', $ticket->key, $name, $e->getMessage()));
            }
        }

        return $paths;
    }

    /**
     * Transitions the issue to the first available workflow step whose target status
     * matches $statusName. No-op when the name is empty or no transition fits.
     */
    private function transitionToStatus(string $key, string $statusName): void
    {
        if ('' === trim($statusName)) {
            return;
        }

        try {
            $data = $this->client->request('GET', \sprintf('rest/api/3/issue/%s/transitions', $key))->toArray();
            foreach ($data['transitions'] ?? [] as $transition) {
                if (!\is_array($transition)) {
                    continue;
                }
                if (($transition['to']['name'] ?? '') !== $statusName) {
                    continue;
                }
                $this->client->request('POST', \sprintf('rest/api/3/issue/%s/transitions', $key), [
                    'json' => ['transition' => ['id' => $transition['id']]],
                ])->getStatusCode();

                return;
            }

            $this->logger->warning(\sprintf('JiraTicketSource: no transition to "%s" for %s', $statusName, $key));
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning(\sprintf('JiraTicketSource::transitionToStatus(%s, %s) failed: %s', $key, $statusName, $e->getMessage()));
        }
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

        $attachments = [];
        foreach ($fields['attachment'] ?? [] as $file) {
            if (!empty($file['content'])) {
                $attachments[] = new Attachment(
                    $file['filename'] ?? 'attachment',
                    (string) $file['content'],
                    $file['mimeType'] ?? '',
                    (int) ($file['size'] ?? 0),
                );
            }
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
            attachments: $attachments,
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
