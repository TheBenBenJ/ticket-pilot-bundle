<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    private function source(MockHttpClient $client): GitHubIssueSource
    {
        return new GitHubIssueSource('https://api.github.com', 'token', 'acme/app', 'ia', 'bug', $client);
    }
}
