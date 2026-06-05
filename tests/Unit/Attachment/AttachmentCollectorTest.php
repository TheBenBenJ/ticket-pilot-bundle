<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Attachment\AttachmentCollector;
use TheBenBenJ\TicketPilotBundle\Attachment\DocumentConverter;
use TheBenBenJ\TicketPilotBundle\Contract\AttachmentDownloaderInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

final class AttachmentCollectorTest extends TestCase
{
    public function testReturnsDownloadedFilesUnchangedWhenConversionDisabled(): void
    {
        $source = new class implements AttachmentDownloaderInterface {
            public function downloadAttachments(Ticket $ticket, string $targetDir): array
            {
                return [$targetDir.'/spec.docx', $targetDir.'/shot.png'];
            }
        };

        $collector = new AttachmentCollector(new DocumentConverter(), convertDocuments: false);
        $files = $collector->collect($source, $this->ticket(), '/tmp/x');

        self::assertSame(['/tmp/x/spec.docx', '/tmp/x/shot.png'], $files);
    }

    public function testKeepsNonConvertibleFilesWhenConversionFails(): void
    {
        $source = new class implements AttachmentDownloaderInterface {
            public function downloadAttachments(Ticket $ticket, string $targetDir): array
            {
                return [$targetDir.'/shot.png'];
            }
        };

        // PNG is not convertible, so it is returned as-is regardless of LibreOffice.
        $collector = new AttachmentCollector(new DocumentConverter('soffice-missing'), convertDocuments: true);

        self::assertSame(['/tmp/x/shot.png'], $collector->collect($source, $this->ticket(), '/tmp/x'));
    }

    private function ticket(): Ticket
    {
        return new Ticket('LYSI-1', 'Title', 'desc', 'Bug', 'jira');
    }
}
