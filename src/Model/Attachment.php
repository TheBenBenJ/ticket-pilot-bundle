<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * A ticket attachment, as advertised by the source. The binary is fetched lazily
 * by the source's downloader (the content URL usually needs authentication).
 */
final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $url,
        public string $mimeType = '',
        public int $size = 0,
    ) {
    }
}
