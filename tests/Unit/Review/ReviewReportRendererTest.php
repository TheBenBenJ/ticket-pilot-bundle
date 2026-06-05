<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Review\ReviewReportRenderer;

final class ReviewReportRendererTest extends TestCase
{
    private function ticket(): Ticket
    {
        return new Ticket('KEY-1', 'A title', 'desc', 'Bug', 'jira', url: 'https://example.test/browse/KEY-1');
    }

    public function testReturnsNullWhenNoOutputDirConfigured(): void
    {
        $renderer = new ReviewReportRenderer('');

        self::assertNull($renderer->render($this->ticket(), true, 'REVIEW PASSED', []));
    }

    public function testReturnsNullAndLeavesNoHtmlBehindWhenBinaryIsMissing(): void
    {
        $dir = sys_get_temp_dir().'/tpb-report-'.bin2hex(random_bytes(4));
        $renderer = new ReviewReportRenderer($dir, '/does/not/exist/soffice', 5);

        self::assertNull($renderer->render($this->ticket(), false, 'REVIEW FAILED', []));
        self::assertSame([], glob($dir.'/*.html'), 'the intermediate HTML must be cleaned up');

        @rmdir($dir);
    }
}
