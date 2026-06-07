<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use TheBenBenJ\TicketPilotBundle\Command\InstallCommand;
use TheBenBenJ\TicketPilotBundle\DependencyInjection\Configuration;

final class InstallCommandTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tpb-install-'.bin2hex(random_bytes(4));
        $this->file = $this->dir.'/config/packages/ticket_pilot.yaml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        @rmdir($this->dir.'/config/packages');
        @rmdir($this->dir.'/config');
        @rmdir($this->dir);
    }

    public function testNonInteractiveWritesDefaults(): void
    {
        $tester = new CommandTester(new InstallCommand($this->dir));
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertFileExists($this->file);

        $yaml = (string) file_get_contents($this->file);
        self::assertStringContainsString('default_source: jira', $yaml);
        self::assertStringContainsString('default_agent: cursor', $yaml);
        self::assertStringContainsString('%env(JIRA_TOKEN)%', $yaml);
        self::assertStringContainsString('gitlab:', $yaml);
        self::assertStringContainsString('%env(GITLAB_TOKEN)%', $yaml);
        // Quality defaults to enabled; review/tracking default off.
        self::assertStringContainsString('quality:', $yaml);
        self::assertStringNotContainsString('review:', $yaml);
        self::assertStringNotContainsString('tracking:', $yaml);
    }

    public function testInteractiveChoicesShapeTheFile(): void
    {
        $tester = new CommandTester(new InstallCommand($this->dir));
        // language, agent, source, vcs, pipeline_ref, feature_base, hotfix_base,
        // commit tpl, draft?, quality?, on_failure, review?, driver, url_pattern, tracking?
        $tester->setInputs([
            'français',     // language
            'claude',       // default agent
            'github',       // source
            'github',       // vcs
            'main',          // pipeline_ref
            'develop',       // feature_base
            'main',          // hotfix_base
            '[{key}] {title} #REVIEW', // commit template
            'yes',           // draft
            'yes',           // quality
            'draft',         // on_failure
            'yes',           // review
            'agent',         // driver
            'https://{branch_slug}.example.com', // url_pattern
            'yes',           // tracking
        ]);
        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $status);
        $yaml = (string) file_get_contents($this->file);
        self::assertStringContainsString('default_source: github', $yaml);
        self::assertStringContainsString('default_agent: claude', $yaml);
        self::assertStringContainsString("language: 'français'", $yaml);
        self::assertStringContainsString('dispatch_event_type', $yaml);
        self::assertStringContainsString('draft: true', $yaml);
        self::assertStringContainsString('review:', $yaml);
        self::assertStringContainsString('driver: agent', $yaml);
        self::assertStringContainsString("url_pattern: 'https://{branch_slug}.example.com'", $yaml);
        self::assertStringContainsString('tracking:', $yaml);
    }

    public function testGeneratedConfigIsValidAgainstTheBundleConfiguration(): void
    {
        // Generate the richest file (review agent + tracking) and process it through
        // the bundle's Configuration, so the installer can never emit invalid config.
        $tester = new CommandTester(new InstallCommand($this->dir));
        $tester->setInputs([
            'English', 'cursor', 'jira', 'gitlab', 'main', 'develop', 'main',
            '[{key}] {title}', 'no', 'yes', 'draft', 'yes', 'agent', '', 'yes',
        ]);
        $tester->execute([], ['interactive' => true]);

        /** @var array{ticket_pilot: array<string, mixed>} $parsed */
        $parsed = Yaml::parseFile($this->file);
        $config = (new Processor())->processConfiguration(new Configuration(), [$parsed['ticket_pilot']]);

        self::assertSame('jira', $config['default_source']);
        self::assertTrue($config['review']['enabled']);
        self::assertSame('agent', $config['review']['driver']);
        self::assertTrue($config['tracking']['enabled']);
    }

    public function testDoesNotOverwriteWithoutForce(): void
    {
        @mkdir(\dirname($this->file), 0o777, true);
        file_put_contents($this->file, "ticket_pilot: {}\n");

        $tester = new CommandTester(new InstallCommand($this->dir));
        $tester->setInputs(['no']); // answer "no" to the overwrite confirmation
        $tester->execute([], ['interactive' => true]);

        self::assertSame("ticket_pilot: {}\n", file_get_contents($this->file));
    }
}
