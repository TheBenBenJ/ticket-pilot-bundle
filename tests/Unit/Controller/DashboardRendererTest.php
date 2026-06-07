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

    public function testHtmlIsEscaped(): void
    {
        $runs = [new RunRecord('1', 'auto-dev', '<script>x</script>', 'success', '2026-01-01T10:00:00+00:00', '', 'a & b')];

        $html = (new DashboardRenderer())->page($runs, '/launch', false, [], [], 'jira', 'cursor');

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('a &amp; b', $html);
    }
}
