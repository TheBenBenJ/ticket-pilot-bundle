<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Run\RunScreenshotEncoder;

final class RunScreenshotEncoderTest extends TestCase
{
    public function testEncodeLocalPngAsDataUri(): void
    {
        $png = sys_get_temp_dir().'/tpb-enc-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($png, 'PNGBYTES');

        $out = RunScreenshotEncoder::toViewable([$png]);
        unlink($png);

        self::assertCount(1, $out);
        self::assertStringStartsWith('data:image/png;base64,', $out[0]);
        self::assertStringContainsString(base64_encode('PNGBYTES'), $out[0]);
    }

    public function testKeepsAlreadyViewableValues(): void
    {
        $url = 'https://host/a.png';
        $data = 'data:image/png;base64,abc';

        self::assertSame([$url, $data], RunScreenshotEncoder::toViewable([$url, $data]));
    }

    public function testMissingFileFallsBackToBasename(): void
    {
        self::assertSame(['missing.png'], RunScreenshotEncoder::toViewable(['/tmp/missing.png']));
    }
}
