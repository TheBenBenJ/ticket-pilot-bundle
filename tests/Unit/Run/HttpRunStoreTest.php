<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheBenBenJ\TicketPilotBundle\Run\HttpRunStore;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;

final class HttpRunStoreTest extends TestCase
{
    public function testRecordPostsTheRunWithTheToken(): void
    {
        $method = $url = $body = '';
        $headers = [];
        $client = new MockHttpClient(static function (string $m, string $u, array $options) use (&$method, &$url, &$headers, &$body): MockResponse {
            $method = $m;
            $url = $u;
            $headers = (array) ($options['headers'] ?? []);
            $body = (string) ($options['body'] ?? '');

            return new MockResponse('', ['http_code' => 201]);
        });

        (new HttpRunStore($client, 'https://host/ia/runs', 'secret-token'))
            ->record(new RunRecord('1', 'auto-dev', 'P-1', 'success', '2026-01-01T10:00:00+00:00'));

        self::assertSame('POST', $method);
        self::assertSame('https://host/ia/runs', $url);
        self::assertContains('X-Ticket-Pilot-Token: secret-token', $headers);
        self::assertStringContainsString('"ticketKey":"P-1"', $body);
    }

    public function testRecordSwallowsTransportErrors(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'boom']);
        });

        // Best-effort: a failed POST must not throw.
        (new HttpRunStore($client, 'https://host/ia/runs', 't'))
            ->record(new RunRecord('1', 'review', 'P-2', 'passed', '2026-01-01T10:00:00+00:00'));

        $this->expectNotToPerformAssertions();
    }

    public function testRecordAttachsLocalScreenshotsAsBase64Files(): void
    {
        $png = sys_get_temp_dir().'/tpb-shot-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($png, 'PNGBYTES');

        $body = '';
        $client = new MockHttpClient(static function (string $m, string $u, array $options) use (&$body): MockResponse {
            $body = (string) ($options['body'] ?? '');

            return new MockResponse('', ['http_code' => 201]);
        });

        (new HttpRunStore($client, 'https://host/ia/runs', 't'))
            ->record(new RunRecord('1', 'review', 'P-1', 'passed', '2026-01-01T10:00:00+00:00', '', '', '', '', '', 0.0, [$png]));

        unlink($png);

        // The screenshot file is base64-encoded into _files; the wire keeps base names
        // in screenshots (the ingest rewrites them to public URLs).
        self::assertStringContainsString('"_files"', $body);
        self::assertStringContainsString(base64_encode('PNGBYTES'), $body);
        self::assertStringContainsString('"name":"'.basename($png).'"', $body);
        self::assertStringContainsString('"screenshots":["'.basename($png).'"]', $body);
    }

    public function testRecentIsNotSupportedRemotely(): void
    {
        self::assertSame([], (new HttpRunStore(new MockHttpClient(), 'https://host/ia/runs', 't'))->recent());
    }
}
