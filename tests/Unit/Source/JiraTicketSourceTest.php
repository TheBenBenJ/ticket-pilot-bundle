<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Source\JiraTicketSource;

final class JiraTicketSourceTest extends TestCase
{
    public function testReportMergeRequestPostsAnAdfComment(): void
    {
        $url = null;
        $body = null;
        $client = new MockHttpClient(static function (string $method, string $u, array $options) use (&$url, &$body): MockResponse {
            $url = $u;
            $body = $options['body'] ?? '';

            return new MockResponse('{}', ['http_code' => 201]);
        });

        $source = new JiraTicketSource('https://jira.example', 'bot@example.com', 'token', 'PROJ', 'IA', 'To Do', $client);
        $source->reportMergeRequest(
            new Ticket('PROJ-1', 'Title', 'desc', 'Bug', 'jira'),
            new MergeRequest(5, 'https://gitlab.example/mr/5'),
        );

        self::assertStringContainsString('/issue/PROJ-1/comment', (string) $url);
        self::assertStringContainsString('"type":"doc"', (string) $body);
        self::assertStringContainsString('https://gitlab.example/mr/5', stripslashes((string) $body));
    }
}
