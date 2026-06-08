<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardRenderer;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;

final class DashboardRendererTest extends TestCase
{
    public function testPageListsRunsAndShowsLaunchFormsWhenAllowed(): void
    {
        $runs = [new RunRecord('1', 'review', 'PROJ-9', 'inconclusive', '2026-01-01T10:00:00+00:00', 'feat/PROJ-9', 'no data on env')];

        $models = ['cursor' => ['auto', 'gpt-4'], 'claude' => ['default', 'opus']];
        $html = (new DashboardRenderer())->page($runs, '/ia/dashboard/launch', true, ['jira'], ['cursor', 'claude'], 'jira', 'cursor', '', $models);

        self::assertStringContainsString('PROJ-9', $html);
        self::assertStringContainsString('inconclusive', $html);
        self::assertStringContainsString('action="/ia/dashboard/launch"', $html);
        self::assertStringContainsString('value="auto-dev"', $html);
        self::assertStringContainsString('value="iterate"', $html);
        self::assertStringContainsString('value="review"', $html);
        self::assertSame(3, substr_count($html, 'class="tp-model"'));
        self::assertStringContainsString('"cursor":["auto","gpt-4"]', $html);
        self::assertStringContainsString('value="auto"', $html);
        self::assertStringContainsString('class="tp-agent"', $html);
    }

    public function testPageHidesLaunchFormsWhenNotAllowed(): void
    {
        $html = (new DashboardRenderer())->page([], '/launch', false, [], [], 'jira', 'cursor');

        self::assertStringNotContainsString('value="auto-dev"', $html);
        self::assertStringContainsString('No run recorded yet', $html);
    }

    public function testTicketTimelineShowsEveryStepWithSummaryAndScreenshots(): void
    {
        $runs = [
            new RunRecord('1', 'auto-dev', 'PROJ-9', 'success', '2026-01-01T10:00:00+00:00', 'feat/PROJ-9', 'Implemented the button', 'http://mr/9'),
            new RunRecord('2', 'iterate', 'PROJ-9', 'success', '2026-01-02T10:00:00+00:00', 'feat/PROJ-9', 'Fixed alignment per feedback'),
            new RunRecord('3', 'review', 'PROJ-9', 'passed', '2026-01-03T10:00:00+00:00', 'feat/PROJ-9', "REVIEW PASSED\nVerified the screen", 'https://pr-9.test', '', 'jira', 0.0, ['home.png', 'detail.png']),
        ];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-9', $runs, '/ia/dashboard');

        self::assertStringContainsString('3 step(s)', $html);
        self::assertStringContainsString('auto-dev', $html);
        self::assertStringContainsString('iterate', $html);
        self::assertStringContainsString('review', $html);
        self::assertStringContainsString('Implemented the button', $html);
        self::assertStringContainsString('Fixed alignment per feedback', $html);
        self::assertStringContainsString('Verified the screen', $html);
        self::assertStringContainsString('home.png', $html);
        self::assertStringContainsString('detail.png', $html);
    }

    public function testTicketTimelineRendersScreenshotUrlsAsImages(): void
    {
        $runs = [new RunRecord('1', 'review', 'PROJ-1', 'passed', '2026-01-01T10:00:00+00:00', '', 'ok', '', '', '', 0.0, ['https://host/shots/a.png'])];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringContainsString('<img src="https://host/shots/a.png"', $html);
    }

    public function testTicketTimelineRendersServedPathsAsImages(): void
    {
        $runs = [new RunRecord('1', 'review', 'PROJ-1', 'passed', '2026-01-01T10:00:00+00:00', '', 'ok', '', '', '', 0.0, ['/ticket-pilot/screenshots/abc/home.png'])];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringContainsString('<img src="/ticket-pilot/screenshots/abc/home.png"', $html);
        // Each viewable shot opens in the inline lightbox (no new tab on thumbnail click).
        self::assertStringContainsString('<figure class="shot">', $html);
        self::assertStringContainsString('class="shot-open"', $html);
        self::assertStringContainsString('id="tp-lightbox"', $html);
        self::assertStringContainsString('<figcaption>home.png</figcaption>', $html);
        self::assertStringNotContainsString('target="_blank" rel="noopener"><img src="/ticket-pilot/screenshots/abc/home.png"', $html);
    }

    public function testTicketTimelineRendersDataUriScreenshotsAsImages(): void
    {
        $data = 'data:image/png;base64,'.base64_encode('PNG');
        $runs = [new RunRecord('1', 'review', 'PROJ-1', 'passed', '2026-01-01T10:00:00+00:00', '', 'ok', '', '', '', 0.0, [$data])];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringContainsString('<img src="'.$data.'"', $html);
        self::assertStringContainsString('<figcaption>screenshot-1.png</figcaption>', $html);
    }

    public function testTicketTimelineRendersReviewScenario(): void
    {
        $runs = [new RunRecord(
            '1',
            'review',
            'PROJ-1',
            'passed',
            '2026-01-01T10:00:00+00:00',
            '',
            'ok',
            '',
            '',
            '',
            0.0,
            [],
            "## Étapes\n1. Ouvrir le planning",
            '/ticket-pilot/scenarios/PROJ-1.md',
        )];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringContainsString('Review scenario', $html);
        self::assertStringContainsString('Ouvrir le planning', $html);
        self::assertStringContainsString('/ticket-pilot/scenarios/PROJ-1.md', $html);
    }

    public function testTicketTimelineFormatsTheSummaryAsMarkdown(): void
    {
        $summary = "## Verdict\nREVIEW **PASSED**\n\n- checked the `home` screen\n- no regression";
        $runs = [new RunRecord('1', 'review', 'PROJ-1', 'passed', '2026-01-01T10:00:00+00:00', '', $summary, '', '', '', 0.0)];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringContainsString('<h5>Verdict</h5>', $html);
        self::assertStringContainsString('<strong>PASSED</strong>', $html);
        self::assertStringContainsString('<ul><li>checked the <code>home</code> screen</li>', $html);
        self::assertStringNotContainsString('<pre>', $html);
    }

    public function testMarkdownEscapesUntrustedSummary(): void
    {
        $runs = [new RunRecord('1', 'review', 'PROJ-1', 'failed', '2026-01-01T10:00:00+00:00', '', '<img src=x onerror=alert(1)> **bold**', '', '', '', 0.0)];

        $html = (new DashboardRenderer())->ticketTimeline('PROJ-1', $runs, '/back');

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testPageEmbedsTheBrandLogoAndColors(): void
    {
        $html = (new DashboardRenderer())->page([], '/launch', false, [], [], 'jira', 'cursor');

        // Inline brand logo (SVG) and the brand green accent.
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('aria-label="Ticket Pilot"', $html);
        self::assertStringContainsString('#02ad72', $html);
    }

    public function testListLinksTicketsToTheDetailPage(): void
    {
        $runs = [new RunRecord('1', 'auto-dev', 'PROJ-9', 'success', '2026-01-01T10:00:00+00:00')];

        $html = (new DashboardRenderer())->page($runs, '/launch', false, [], [], 'jira', 'cursor', '/ia/dashboard/__TICKET__');

        self::assertStringContainsString('href="/ia/dashboard/PROJ-9"', $html);
    }

    public function testHtmlIsEscaped(): void
    {
        $runs = [new RunRecord('1', 'auto-dev', '<script>x</script>', 'success', '2026-01-01T10:00:00+00:00', '', 'a & b')];

        $html = (new DashboardRenderer())->page($runs, '/launch', false, [], [], 'jira', 'cursor');

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('a &amp; b', $html);
    }
}
