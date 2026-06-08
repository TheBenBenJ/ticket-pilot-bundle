<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Run\RunScenarioPersister;

final class RunScenarioPersisterTest extends TestCase
{
    public function testPersistWritesMarkdownAndReturnsPublicUrl(): void
    {
        $dir = sys_get_temp_dir().'/tpb-scenario-'.uniqid();
        $persister = new RunScenarioPersister($dir, '/ticket-pilot/scenarios');

        $url = $persister->persist('LYSI-42', "# Steps\n1. Login");

        self::assertSame('/ticket-pilot/scenarios/LYSI-42.md', $url);
        self::assertFileExists($dir.'/LYSI-42.md');
        self::assertStringContainsString('1. Login', (string) file_get_contents($dir.'/LYSI-42.md'));

        @unlink($dir.'/LYSI-42.md');
        @rmdir($dir);
    }

    public function testPersistReturnsEmptyWhenDisabled(): void
    {
        $persister = new RunScenarioPersister('', '');

        self::assertSame('', $persister->persist('LYSI-1', 'content'));
    }
}
