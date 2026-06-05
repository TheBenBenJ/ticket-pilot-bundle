<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Attachment\DocumentConverter;

final class DocumentConverterTest extends TestCase
{
    public function testSupportsOfficeDocumentsOnly(): void
    {
        $converter = new DocumentConverter();

        self::assertTrue($converter->supports('spec.docx'));
        self::assertTrue($converter->supports('/path/Notes.ODT'));
        self::assertFalse($converter->supports('screenshot.png'));
        self::assertFalse($converter->supports('report.pdf'));
    }

    public function testToPdfReturnsNullForUnsupportedOrMissingFile(): void
    {
        self::assertNull((new DocumentConverter())->toPdf('image.png'));
        self::assertNull((new DocumentConverter())->toPdf('/does/not/exist.docx'));
    }

    public function testToPdfReturnsNullWhenLibreOfficeIsUnavailable(): void
    {
        $docx = sys_get_temp_dir().'/tpb_'.uniqid().'.docx';
        file_put_contents($docx, 'not really a docx');

        try {
            $converter = new DocumentConverter('soffice-this-binary-does-not-exist');
            self::assertNull($converter->toPdf($docx));
        } finally {
            @unlink($docx);
        }
    }
}
