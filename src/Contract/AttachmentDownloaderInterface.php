<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Optional capability a {@see TicketSourceInterface} may implement to download a
 * ticket's attachments (the content URL usually needs the source's authentication).
 */
interface AttachmentDownloaderInterface
{
    /**
     * Downloads the ticket's attachments into $targetDir.
     *
     * @return list<string> Absolute paths of the saved files
     */
    public function downloadAttachments(Ticket $ticket, string $targetDir): array;
}
