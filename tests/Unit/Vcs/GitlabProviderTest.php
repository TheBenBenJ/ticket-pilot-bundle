<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Vcs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheBenBenJ\TicketPilotBundle\Vcs\GitlabProvider;

final class GitlabProviderTest extends TestCase
{
    public function testDraftMergeRequestPrefixesTheTitle(): void
    {
        self::assertStringContainsString('"title":"Draft: Fix it"', $this->sentBodyFor(true));
    }

    public function testNonDraftMergeRequestKeepsThePlainTitle(): void
    {
        $body = $this->sentBodyFor(false);
        self::assertStringContainsString('"title":"Fix it"', $body);
        self::assertStringNotContainsString('Draft:', $body);
    }

    private function sentBodyFor(bool $draft): string
    {
        $body = '';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            if (str_contains($url, '/merge_requests')) {
                $body = $options['body'] ?? '';

                return new MockResponse((string) json_encode(['iid' => 3, 'web_url' => 'u']), ['http_code' => 201]);
            }

            // Project id lookup.
            return new MockResponse((string) json_encode(['id' => 1]));
        });

        (new GitlabProvider('https://gitlab.example', 'token', 'group/project', $client))
            ->createMergeRequest('feature/1', 'main', 'Fix it', 'Body', $draft);

        return (string) $body;
    }
}
