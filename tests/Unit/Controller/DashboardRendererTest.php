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

        $html = (new DashboardRenderer())->page($runs, '/ia/dashboard/launch', true, ['jira'], ['cursor', 'claude'], 'jira', 'cursor');

        self::assertStringContainsString('PROJ-9', $html);
        self::assertStringContainsString('inconclusive', $html);
        self::assertStringContainsString('action="/ia/dashboard/launch"', $html);
        self::assertStringContainsString('value="auto-dev"', $html);
        self::assertStringContainsString('value="iterate"', $html);
        self::assertStringContainsString('value="review"', $html);
        // Every launch form lets you pick the model.
        self::assertSame(3, substr_count($html, 'name="model"'));
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
