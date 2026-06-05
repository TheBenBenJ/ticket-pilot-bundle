<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBenBenJ\TicketPilotBundle\Agent\ClaudeAgent;
use TheBenBenJ\TicketPilotBundle\Agent\CursorAgent;
use TheBenBenJ\TicketPilotBundle\Command\AutoDevCommand;
use TheBenBenJ\TicketPilotBundle\Command\CreateMergeRequestCommand;
use TheBenBenJ\TicketPilotBundle\Command\ListTicketsCommand;
use TheBenBenJ\TicketPilotBundle\Command\ReviewCommand;
use TheBenBenJ\TicketPilotBundle\Command\ShowPromptCommand;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\RecipeRunnerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Controller\TriggerPipelineController;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Prompt\DefaultPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Quality\CommandQualityGate;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Review\ChromeRecipeRunner;
use TheBenBenJ\TicketPilotBundle\Review\RecipeExecutor;
use TheBenBenJ\TicketPilotBundle\Review\RecipeFactory;
use TheBenBenJ\TicketPilotBundle\Review\RecipeRepository;
use TheBenBenJ\TicketPilotBundle\Review\ReviewUrlResolver;
use TheBenBenJ\TicketPilotBundle\Security\TicketGuard;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevOptions;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevRunner;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;
use TheBenBenJ\TicketPilotBundle\Source\GitHubIssueSource;
use TheBenBenJ\TicketPilotBundle\Source\JiraTicketSource;
use TheBenBenJ\TicketPilotBundle\Source\SentryTicketSource;
use TheBenBenJ\TicketPilotBundle\Vcs\GitHubProvider;
use TheBenBenJ\TicketPilotBundle\Vcs\GitlabProvider;

final class TicketPilotExtension extends Extension
{
    public const TAG_SOURCE = 'ticket_pilot.ticket_source';
    public const TAG_AGENT = 'ticket_pilot.agent';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), $configs);

        $projectDir = $config['project_dir'];
        $logger = new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE);
        $httpClient = $this->httpClient($config, $logger);

        $this->registerCoreServices($container, $config, $projectDir, $logger);
        $this->registerSources($container, $config, $httpClient, $logger);
        $this->registerAgents($container, $config, $projectDir);
        $hasVcs = $this->registerVcs($container, $config, $httpClient, $logger);
        $this->registerQuality($container, $config, $projectDir, $logger);
        $this->registerOrchestration($container, $config, $projectDir, $hasVcs);
        $this->registerReview($container, $config, $projectDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerReview(ContainerBuilder $container, array $config, string $projectDir): void
    {
        if (!$config['review']['enabled']) {
            return;
        }

        $review = $config['review'];
        $base = rtrim($projectDir, '/');

        $container->setDefinition(RecipeFactory::class, new Definition(RecipeFactory::class));
        $container->setDefinition(RecipeRepository::class, new Definition(RecipeRepository::class, [
            $base.'/'.ltrim($review['recipes_dir'], '/'),
            new Reference(RecipeFactory::class),
        ]));
        $container->setDefinition(RecipeExecutor::class, new Definition(RecipeExecutor::class, [
            $base.'/'.ltrim($review['screenshot_dir'], '/'),
            $review['wait_timeout'],
        ]));
        $container->setDefinition(ChromeRecipeRunner::class, new Definition(ChromeRecipeRunner::class, [
            new Reference(RecipeExecutor::class),
            $review['chrome_binary'],
            ['headless' => true],
        ]));
        $container->setAlias(RecipeRunnerInterface::class, ChromeRecipeRunner::class);
        $container->setDefinition(ReviewUrlResolver::class, new Definition(ReviewUrlResolver::class, [$review['url_pattern']]));

        $this->registerCommand($container, ReviewCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(RecipeRepository::class),
            new Reference(ReviewUrlResolver::class),
            new Reference(RecipeRunnerInterface::class),
            '%ticket_pilot.default_source%',
        ]);
    }

    public function getAlias(): string
    {
        return 'ticket_pilot';
    }

    /**
     * The HTTP client used by the API integrations, optionally wrapped in a
     * RetryableHttpClient so transient failures (timeouts, 5xx, 429) are retried.
     *
     * @param array<string, mixed> $config
     */
    private function httpClient(array $config, Reference $logger): Reference|Definition
    {
        $client = new Reference(HttpClientInterface::class);
        $maxRetries = $config['http']['max_retries'];

        if ($maxRetries < 1) {
            return $client;
        }

        return new Definition(RetryableHttpClient::class, [$client, null, $maxRetries, $logger]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCoreServices(ContainerBuilder $container, array $config, string $projectDir, Reference $logger): void
    {
        $container->setParameter('ticket_pilot.default_source', $config['default_source']);
        $container->setParameter('ticket_pilot.default_agent', $config['default_agent']);

        $sourceRegistry = new Definition(TicketSourceRegistry::class, [new TaggedIteratorArgument(self::TAG_SOURCE)]);
        $sourceRegistry->setPublic(true);
        $container->setDefinition(TicketSourceRegistry::class, $sourceRegistry);

        $agentRegistry = new Definition(AgentRegistry::class, [new TaggedIteratorArgument(self::TAG_AGENT)]);
        $agentRegistry->setPublic(true);
        $container->setDefinition(AgentRegistry::class, $agentRegistry);

        $container->setDefinition(GitClient::class, new Definition(GitClient::class, [$projectDir, $config['git_timeout']]));
        $container->setAlias(GitInterface::class, GitClient::class);

        $container->setDefinition(TicketGuard::class, new Definition(TicketGuard::class, [
            $config['security']['trusted_reporters'],
        ]));

        $prompt = $config['prompt'];
        $reviewRecipePath = ($config['review']['enabled'] && $config['review']['write_recipe'])
            ? rtrim($config['review']['recipes_dir'], '/').'/{key}.yaml'
            : '';

        $container->setDefinition(DefaultPromptBuilder::class, new Definition(DefaultPromptBuilder::class, [
            $prompt['language'],
            $prompt['quality_commands'],
            $prompt['summary_start_marker'],
            $prompt['summary_end_marker'],
            $prompt['extra_instructions'],
            $projectDir,
            $prompt['convention_files'],
            $reviewRecipePath,
        ]));
        $container->setAlias(PromptBuilderInterface::class, DefaultPromptBuilder::class)->setPublic(true);

        $branching = $config['branching'];
        $container->setDefinition(BranchPlanner::class, new Definition(BranchPlanner::class, [
            new Reference(GitClient::class),
            $branching['feature_base'],
            $branching['hotfix_base'],
            $branching['feature_prefix'],
            $branching['hotfix_prefix'],
            $branching['release_branch_pattern'],
            $branching['bug_types'],
            $logger,
        ]));

        $container->setDefinition(MergeRequestFactory::class, new Definition(MergeRequestFactory::class, [
            $prompt['summary_start_marker'],
            $prompt['summary_end_marker'],
            $config['merge_request']['commit_message_template'],
        ]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSources(ContainerBuilder $container, array $config, Reference|Definition $httpClient, Reference $logger): void
    {
        $jira = $config['sources']['jira'];
        if ($jira['enabled']) {
            $definition = new Definition(JiraTicketSource::class, [
                $jira['base_uri'],
                $jira['email'],
                $jira['token'],
                $jira['project'],
                $jira['pending_label'],
                $jira['pending_status'],
                $httpClient,
                $logger,
            ]);
            $definition->addTag(self::TAG_SOURCE);
            $container->setDefinition(JiraTicketSource::class, $definition);
        }

        $sentry = $config['sources']['sentry'];
        if ($sentry['enabled']) {
            $definition = new Definition(SentryTicketSource::class, [
                $sentry['base_uri'],
                $sentry['token'],
                $sentry['organization'],
                $sentry['project'],
                $httpClient,
                $logger,
            ]);
            $definition->addTag(self::TAG_SOURCE);
            $container->setDefinition(SentryTicketSource::class, $definition);
        }

        $github = $config['sources']['github'];
        if ($github['enabled']) {
            $definition = new Definition(GitHubIssueSource::class, [
                $github['base_uri'],
                $github['token'],
                $github['repository'],
                $github['pending_label'],
                $github['bug_label'],
                $httpClient,
                $logger,
            ]);
            $definition->addTag(self::TAG_SOURCE);
            $container->setDefinition(GitHubIssueSource::class, $definition);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerAgents(ContainerBuilder $container, array $config, string $projectDir): void
    {
        $timeout = $config['agent_timeout'];

        $cursor = $config['agents']['cursor'];
        if ($cursor['enabled']) {
            $definition = new Definition(CursorAgent::class, [$projectDir, $cursor['binary'], $timeout]);
            $definition->addTag(self::TAG_AGENT);
            $container->setDefinition(CursorAgent::class, $definition);
        }

        $claude = $config['agents']['claude'];
        if ($claude['enabled']) {
            $definition = new Definition(ClaudeAgent::class, [$projectDir, $claude['binary'], $claude['skip_permissions'], $timeout]);
            $definition->addTag(self::TAG_AGENT);
            $container->setDefinition(ClaudeAgent::class, $definition);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return bool whether a VCS provider was registered
     */
    private function registerVcs(ContainerBuilder $container, array $config, Reference|Definition $httpClient, Reference $logger): bool
    {
        $gitlab = $config['vcs']['gitlab'];
        $github = $config['vcs']['github'];

        if ($gitlab['enabled']) {
            $providerClass = GitlabProvider::class;
            $pipelineRef = $gitlab['pipeline_ref'];
            $container->setDefinition($providerClass, new Definition($providerClass, [
                $gitlab['base_uri'],
                $gitlab['token'],
                $gitlab['project_path'],
                $httpClient,
                $logger,
            ]));
        } elseif ($github['enabled']) {
            $providerClass = GitHubProvider::class;
            $pipelineRef = $github['pipeline_ref'];
            $container->setDefinition($providerClass, new Definition($providerClass, [
                $github['base_uri'],
                $github['token'],
                $github['repository'],
                $github['dispatch_event_type'],
                $httpClient,
                $logger,
            ]));
        } else {
            return false;
        }

        $container->setAlias(VcsProviderInterface::class, $providerClass)->setPublic(true);
        $container->setAlias(PipelineTriggerInterface::class, $providerClass);
        $container->setParameter('ticket_pilot.pipeline_ref', $pipelineRef);

        $this->registerPipelineController($container, $pipelineRef);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerQuality(ContainerBuilder $container, array $config, string $projectDir, Reference $logger): void
    {
        if (!$config['quality']['enabled']) {
            return;
        }

        $definition = new Definition(CommandQualityGate::class, [
            $projectDir,
            $config['quality']['commands'],
            $config['quality']['timeout'],
            $logger,
        ]);
        $container->setDefinition(CommandQualityGate::class, $definition);
        $container->setAlias(QualityGateInterface::class, CommandQualityGate::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerOrchestration(ContainerBuilder $container, array $config, string $projectDir, bool $hasVcs): void
    {
        // Read-only commands work without a VCS provider.
        $this->registerCommand($container, ListTicketsCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            '%ticket_pilot.default_source%',
        ]);
        $this->registerCommand($container, ShowPromptCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(PromptBuilderInterface::class),
            '%ticket_pilot.default_source%',
        ]);

        if (!$hasVcs) {
            return;
        }

        $options = new Definition(AutoDevOptions::class, [
            $config['commit']['exclude_paths'],
            $config['merge_request']['draft'],
            $config['quality']['on_failure'],
            $config['cleanup_branch_on_failure'],
            $config['agent_timeout'],
        ]);

        $container->setDefinition(AutoDevRunner::class, new Definition(AutoDevRunner::class, [
            new Reference(AgentRegistry::class),
            new Reference(PromptBuilderInterface::class),
            new Reference(BranchPlanner::class),
            new Reference(MergeRequestFactory::class),
            new Reference(GitClient::class),
            new Reference(VcsProviderInterface::class),
            $options,
            $config['quality']['enabled'] ? new Reference(QualityGateInterface::class) : null,
            new Reference('event_dispatcher', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            new Reference('lock.factory', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]));

        $this->registerCommand($container, AutoDevCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(AgentRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(AutoDevRunner::class),
            new Reference(TicketGuard::class),
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
        ]);
        $this->registerCommand($container, CreateMergeRequestCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(MergeRequestFactory::class),
            new Reference(VcsProviderInterface::class),
            '%ticket_pilot.default_source%',
        ]);
    }

    private function registerPipelineController(ContainerBuilder $container, string $pipelineRef): void
    {
        $definition = new Definition(TriggerPipelineController::class, [
            new Reference(PipelineTriggerInterface::class),
            new Reference(TicketSourceRegistry::class),
            new Reference(AgentRegistry::class),
            $pipelineRef,
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
        ]);
        $definition->addTag('controller.service_arguments');
        $definition->setPublic(true);
        $container->setDefinition(TriggerPipelineController::class, $definition);
    }

    /**
     * @param class-string $class
     * @param list<mixed>  $arguments
     */
    private function registerCommand(ContainerBuilder $container, string $class, array $arguments): void
    {
        $definition = new Definition($class, $arguments);
        $definition->addTag('console.command');
        $container->setDefinition($class, $definition);
    }
}
