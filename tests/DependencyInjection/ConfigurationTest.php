<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use TheBenBenJ\TicketPilotBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsAreSane(): void
    {
        $config = $this->process([]);

        self::assertSame('jira', $config['default_source']);
        self::assertSame('cursor', $config['default_agent']);
        self::assertFalse($config['sources']['jira']['enabled']);
        self::assertTrue($config['agents']['cursor']['enabled']);
        self::assertSame('develop', $config['branching']['feature_base']);
        self::assertSame('release/RC-{version}', $config['branching']['release_branch_pattern']);
        self::assertSame('[{key}] {title}', $config['merge_request']['commit_message_template']);
        self::assertFalse($config['merge_request']['draft']);
        self::assertSame('abort', $config['quality']['on_failure']);
    }

    public function testInvalidQualityOnFailurePolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['quality' => ['enabled' => true, 'on_failure' => 'whatever']]]);
    }

    public function testEnablingJiraRequiresCredentials(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['sources' => ['jira' => ['enabled' => true]]]]);
    }

    public function testValidJiraConfigurationIsAccepted(): void
    {
        $config = $this->process([[
            'sources' => ['jira' => [
                'enabled' => true,
                'base_uri' => 'https://jira.example',
                'email' => 'bot@example.com',
                'token' => 'secret',
                'project' => 'PROJ',
            ]],
        ]]);

        self::assertTrue($config['sources']['jira']['enabled']);
        self::assertSame('IA', $config['sources']['jira']['pending_label']);
    }

    public function testEnablingBothVcsProvidersIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([[
            'vcs' => [
                'gitlab' => ['enabled' => true, 'base_uri' => 'https://gl', 'token' => 't', 'project_path' => 'g/p'],
                'github' => ['enabled' => true, 'token' => 't', 'repository' => 'o/r'],
            ],
        ]]);
    }

    public function testGitHubSourceDefaults(): void
    {
        $config = $this->process([[
            'sources' => ['github' => ['enabled' => true, 'token' => 't', 'repository' => 'acme/app']],
        ]]);

        self::assertSame('https://api.github.com', $config['sources']['github']['base_uri']);
        self::assertSame('ia', $config['sources']['github']['pending_label']);
        self::assertSame('bug', $config['sources']['github']['bug_label']);
    }

    public function testQualityCommandsAreLabelledArgvLists(): void
    {
        $config = $this->process([['quality' => [
            'enabled' => true,
            'commands' => ['lint' => ['composer', 'lint']],
        ]]]);

        self::assertSame(['lint' => ['composer', 'lint']], $config['quality']['commands']);
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }
}
