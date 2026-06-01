<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Vcs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheBenBenJ\TicketPilotBundle\Vcs\GitHubProvider;

final class GitHubProviderTest extends TestCase
{
    public function testCreateMergeRequestOpensAPullRequest(): void
    {
        $client = new MockHttpClient([
            new MockResponse((string) json_encode(['number' => 7, 'html_url' => 'https://github.com/acme/app/pull/7']), ['http_code' => 201]),
        ]);

        $mr = $this->provider($client)->createMergeRequest('feature/42', 'main', 'Title', 'Body');

        self::assertSame(7, $mr->number);
        self::assertSame('https://github.com/acme/app/pull/7', $mr->url);
    }

    public function testDraftFlagIsSentInThePullRequestPayload(): void
    {
        $body = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'] ?? '';

            return new MockResponse((string) json_encode(['number' => 8, 'html_url' => 'u']), ['http_code' => 201]);
        });

        $this->provider($client)->createMergeRequest('feature/42', 'main', 'Title', 'Body', true);

        self::assertStringContainsString('"draft":true', (string) $body);
    }

    public function testRemoteBranchExistsReflectsHttpStatus(): void
    {
        $present = $this->provider(new MockHttpClient([new MockResponse('{}', ['http_code' => 200])]));
        $absent = $this->provider(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));

        self::assertTrue($present->remoteBranchExists('feature/42'));
        self::assertFalse($absent->remoteBranchExists('feature/42'));
    }

    public function testTriggerPipelineDispatchesAndReportsActionsPage(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 204])]);

        $pipeline = $this->provider($client)->triggerPipeline('main', ['IA_TICKET' => '42']);

        self::assertSame('dispatched', $pipeline->status);
        self::assertStringContainsString('/acme/app/actions', $pipeline->url);
    }

    private function provider(MockHttpClient $client): GitHubProvider
    {
        return new GitHubProvider('https://api.github.com', 'token', 'acme/app', 'ia-auto-dev', $client);
    }
}
