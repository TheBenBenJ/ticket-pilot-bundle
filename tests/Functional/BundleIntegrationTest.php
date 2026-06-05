<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Controller\TriggerPipelineController;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\TicketPilotBundle;

final class BundleIntegrationTest extends TestCase
{
    private ?TestKernel $kernel = null;

    public function testRouteResourceResolvesUnderBundlePath(): void
    {
        // Guards the @TicketPilotBundle/Resources/config/routes.php import used to
        // expose the pipeline-trigger endpoint.
        self::assertFileExists((new TicketPilotBundle())->getPath().'/Resources/config/routes.php');
    }

    public function testContainerCompilesWithDefaultsAndRegistersAgents(): void
    {
        $kernel = $this->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $agents = $container->get(AgentRegistry::class);
        self::assertInstanceOf(AgentRegistry::class, $agents);
        self::assertContains('cursor', $agents->names());
        self::assertContains('claude', $agents->names());

        // Without an enabled VCS provider, only the read-only commands are available.
        $application = new Application($kernel);
        self::assertTrue($application->has('ia:tickets:list'));
        self::assertFalse($application->has('ia:auto-dev'));
    }

    public function testFullyEnabledStackWiresEveryService(): void
    {
        $kernel = $this->boot([
            'sources' => [
                'jira' => [
                    'enabled' => true,
                    'base_uri' => 'https://jira.example',
                    'email' => 'bot@example.com',
                    'token' => 'secret',
                    'project' => 'PROJ',
                ],
                'sentry' => [
                    'enabled' => true,
                    'token' => 'secret',
                    'organization' => 'org',
                    'project' => 'proj',
                ],
            ],
            'vcs' => [
                'gitlab' => [
                    'enabled' => true,
                    'base_uri' => 'https://gitlab.example',
                    'token' => 'secret',
                    'project_path' => 'group/project',
                ],
            ],
            'quality' => ['enabled' => true],
        ]);

        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $sources = $container->get(TicketSourceRegistry::class);
        self::assertInstanceOf(TicketSourceRegistry::class, $sources);
        self::assertContains('jira', $sources->names());
        self::assertContains('sentry', $sources->names());
        self::assertInstanceOf(VcsProviderInterface::class, $container->get(VcsProviderInterface::class));
        self::assertInstanceOf(PromptBuilderInterface::class, $container->get(PromptBuilderInterface::class));
        self::assertInstanceOf(TriggerPipelineController::class, $container->get(TriggerPipelineController::class));

        $application = new Application($kernel);
        self::assertTrue($application->has('ia:auto-dev'));
        self::assertTrue($application->has('ia:merge-request'));
        self::assertTrue($application->has('ia:prompt'));
    }

    public function testGitHubStackWiresSourceAndProvider(): void
    {
        $kernel = $this->boot([
            'default_source' => 'github',
            'sources' => [
                'github' => ['enabled' => true, 'token' => 'secret', 'repository' => 'acme/app'],
            ],
            'vcs' => [
                'github' => ['enabled' => true, 'token' => 'secret', 'repository' => 'acme/app'],
            ],
        ]);

        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $sources = $container->get(TicketSourceRegistry::class);
        self::assertInstanceOf(TicketSourceRegistry::class, $sources);
        self::assertContains('github', $sources->names());
        self::assertInstanceOf(VcsProviderInterface::class, $container->get(VcsProviderInterface::class));

        $application = new Application($kernel);
        self::assertTrue($application->has('ia:auto-dev'));
    }

    public function testReviewEnabledWiresTheReviewCommand(): void
    {
        $kernel = $this->boot([
            'sources' => ['github' => ['enabled' => true, 'token' => 'secret', 'repository' => 'acme/app']],
            'review' => ['enabled' => true, 'url_pattern' => 'https://{branch_slug}.review.example.com'],
        ]);

        self::assertTrue((new Application($kernel))->has('ia:review'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): TestKernel
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();

        return $this->kernel;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        // The kernel registers a Symfony exception handler on boot; restore the
        // one PHPUnit had in place so the test is not flagged as risky.
        restore_exception_handler();
    }
}
