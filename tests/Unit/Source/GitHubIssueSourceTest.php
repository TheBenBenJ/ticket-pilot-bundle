<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Source\GitHubIssueSource;

final class GitHubIssueSourceTest extends TestCase
{
    public function testFetchPendingSkipsPullRequests(): void
    {
        $payload = [
            ['number' => 10, 'title' => 'Feature', 'body' => '', 'labels' => [], 'html_url' => 'u'],
            ['number' => 11, 'title' => 'A PR', 'pull_request' => ['url' => 'x'], 'labels' => []],
            ['number' => 12, 'title' => 'Bug', 'body' => '', 'labels' => [['name' => 'bug']], 'html_url' => 'u'],
        ];
        $source = $this->source(new MockHttpClient([new MockResponse((string) json_encode($payload))]));

        $tickets = $source->fetchPending(5);

        self::assertCount(2, $tickets);
        self::assertSame(['10', '12'], array_map(static fn ($t) => $t->key, $tickets));
        self::assertSame('Task', $tickets[0]->type);
        self::assertSame('Bug', $tickets[1]->type);
    }

    public function testFetchOneMapsLabelsCommentsAndBugType(): void
    {
        $issue = [
            'number' => 42,
            'title' => 'Crash on save',
            'body' => 'It crashes.',
            'labels' => [['name' => 'bug'], ['name' => 'ia']],
            'user' => ['login' => 'reporter'],
            'assignee' => ['login' => 'dev'],
            'html_url' => 'https://github.com/acme/app/issues/42',
        ];
        $comments = [['user' => ['login' => 'alice'], 'body' => 'Reproduced.']];

        $source = $this->source(new MockHttpClient([
            new MockResponse((string) json_encode($issue)),
            new MockResponse((string) json_encode($comments)),
        ]));

        $ticket = $source->fetchOne('#42');

        self::assertSame('42', $ticket->key);
        self::assertSame('Crash on save', $ticket->title);
        self::assertTrue($ticket->isBug());
        self::assertSame(['bug', 'ia'], $ticket->labels);
        self::assertSame(['[alice] Reproduced.'], $ticket->comments);
        self::assertSame('dev', $ticket->assignee);
    }

    public function testReportMergeRequestPostsACommentOnTheIssue(): void
    {
        $url = null;
        $body = null;
        $client = new MockHttpClient(static function (string $method, string $u, array $options) use (&$url, &$body): MockResponse {
            $url = $u;
            $body = $options['body'] ?? '';

            return new MockResponse('{}', ['http_code' => 201]);
        });

        $ticket = new Ticket('42', 'Bug', 'desc', 'Bug', 'github');
        $this->source($client)->reportMergeRequest($ticket, new MergeRequest(9, 'https://github.com/acme/app/pull/9'));

        self::assertStringContainsString('/issues/42/comments', (string) $url);
        self::assertStringContainsString('https://github.com/acme/app/pull/9', stripslashes((string) $body));
    }

    private function source(MockHttpClient $client): GitHubIssueSource
    {
        return new GitHubIssueSource('https://api.github.com', 'token', 'acme/app', 'ia', 'bug', $client);
    }
}
