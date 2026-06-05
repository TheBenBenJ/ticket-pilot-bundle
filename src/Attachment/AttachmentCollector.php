<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Attachment;

use TheBenBenJ\TicketPilotBundle\Contract\AttachmentDownloaderInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Downloads a ticket's attachments via its source, then converts office documents
 * to PDF so the coding agent can read them. Returns the final list of files.
 */
final class AttachmentCollector
{
    public function __construct(
        private readonly DocumentConverter $converter,
        private readonly bool $convertDocuments = true,
    ) {
    }

    /**
     * @return list<string> Final file paths (converted documents replaced by their PDF)
     */
    public function collect(AttachmentDownloaderInterface $source, Ticket $ticket, string $dir): array
    {
        $downloaded = $source->downloadAttachments($ticket, $dir);
        if (!$this->convertDocuments) {
            return $downloaded;
        }

        $files = [];
        foreach ($downloaded as $file) {
            if ($this->converter->supports($file)) {
                $pdf = $this->converter->toPdf($file);
                if (null !== $pdf) {
                    @unlink($file);
                    $files[] = $pdf;

                    continue;
                }
            }
            $files[] = $file;
        }

        return $files;
    }
}
