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
use TheBenBenJ\TicketPilotBundle\Agent\AgentModelCatalog;
use TheBenBenJ\TicketPilotBundle\Agent\ClaudeAgent;
use TheBenBenJ\TicketPilotBundle\Agent\CursorAgent;
use TheBenBenJ\TicketPilotBundle\Attachment\AttachmentCollector;
use TheBenBenJ\TicketPilotBundle\Attachment\DocumentConverter;
use TheBenBenJ\TicketPilotBundle\Command\AutoDevCommand;
use TheBenBenJ\TicketPilotBundle\Command\CreateMergeRequestCommand;
use TheBenBenJ\TicketPilotBundle\Command\InstallCommand;
use TheBenBenJ\TicketPilotBundle\Command\IterateCommand;
use TheBenBenJ\TicketPilotBundle\Command\ListTicketsCommand;
use TheBenBenJ\TicketPilotBundle\Command\ReviewCommand;
use TheBenBenJ\TicketPilotBundle\Command\RunsCommand;
use TheBenBenJ\TicketPilotBundle\Command\ShowPromptCommand;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestReaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\RecipeRunnerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardController;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardLaunchController;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardRenderer;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardTicketController;
use TheBenBenJ\TicketPilotBundle\Controller\RunIngestController;
use TheBenBenJ\TicketPilotBundle\Controller\TriggerPipelineController;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Prompt\DefaultPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Prompt\IteratePromptBuilder;
use TheBenBenJ\TicketPilotBundle\Quality\CommandQualityGate;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewRunner;
use TheBenBenJ\TicketPilotBundle\Review\ChromeRecipeRunner;
use TheBenBenJ\TicketPilotBundle\Review\RecipeExecutor;
use TheBenBenJ\TicketPilotBundle\Review\RecipeFactory;
use TheBenBenJ\TicketPilotBundle\Review\RecipeRepository;
use TheBenBenJ\TicketPilotBundle\Review\ReviewReportRenderer;
use TheBenBenJ\TicketPilotBundle\Review\ReviewUrlResolver;
use TheBenBenJ\TicketPilotBundle\Run\HttpRunStore;
use TheBenBenJ\TicketPilotBundle\Run\JsonlRunStore;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;
use TheBenBenJ\TicketPilotBundle\Security\TicketGuard;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevOptions;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevRunner;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\IterateRunner;
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
        $this->registerAttachments($container, $config);
        $this->registerTracking($container, $config, $projectDir, $hasVcs);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTracking(ContainerBuilder $container, array $config, string $projectDir, bool $hasVcs): void
    {
        if (!$config['tracking']['enabled']) {
            return;
        }

        $resolved = $this->resolveProjectPath((string) $config['tracking']['path'], $projectDir);
        $screenshotsDir = $this->resolveProjectPath((string) $config['tracking']['screenshots_dir'], $projectDir);
        $screenshotsBaseUrl = (string) $config['tracking']['screenshots_base_url'];

        // The canonical store is always the local JSONL file (the env that serves
        // the dashboard owns it). The dashboard, the CLI and the ingest endpoint
        // always read/write THIS file.
        $container->setDefinition(JsonlRunStore::class, new Definition(JsonlRunStore::class, [$resolved]));

        // What the runner commands WRITE through: a remote HTTP store when
        // tracking.remote_url is set (throw-away CI containers forward their runs
        // to the dashboard env), the local file otherwise.
        $remoteUrl = (string) $config['tracking']['remote_url'];
        $ingestToken = (string) $config['tracking']['ingest_token'];
        if ('' !== $remoteUrl) {
            $container->setDefinition(HttpRunStore::class, new Definition(HttpRunStore::class, [
                new Reference(HttpClientInterface::class),
                $remoteUrl,
                $ingestToken,
                new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ]));
            $container->setAlias(RunStoreInterface::class, HttpRunStore::class)->setPublic(true);
        } else {
            $container->setAlias(RunStoreInterface::class, JsonlRunStore::class)->setPublic(true);
        }

        // ia:runs lists the canonical file.
        $this->registerCommand($container, RunsCommand::class, [new Reference(JsonlRunStore::class)]);

        // Ingest endpoint: remote pipelines POST their runs here; appended to the
        // canonical local file. The route is opt-in (bundle routes import).
        $ingest = new Definition(RunIngestController::class, [
            new Reference(JsonlRunStore::class),
            $ingestToken,
            $screenshotsDir,
            $screenshotsBaseUrl,
        ]);
        $ingest->addTag('controller.service_arguments');
        $ingest->setPublic(true);
        $container->setDefinition(RunIngestController::class, $ingest);

        if (!$config['tracking']['dashboard']) {
            return;
        }

        $container->setDefinition(DashboardRenderer::class, new Definition(DashboardRenderer::class));

        $dashboard = new Definition(DashboardController::class, [
            new Reference(JsonlRunStore::class),
            new Reference(DashboardRenderer::class),
            new Reference('router'),
            new Reference(TicketSourceRegistry::class),
            new Reference(AgentRegistry::class),
            new Reference(AgentModelCatalog::class),
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
            $hasVcs,
            $config['agents']['cursor']['enabled'] ? (string) $config['agents']['cursor']['binary'] : '',
            $config['agents']['claude']['enabled'] ? (string) $config['agents']['claude']['binary'] : '',
        ]);
        $dashboard->addTag('controller.service_arguments');
        $dashboard->setPublic(true);
        $container->setDefinition(DashboardController::class, $dashboard);

        $launch = new Definition(DashboardLaunchController::class, [
            new Reference(DashboardRenderer::class),
            new Reference('router'),
            $hasVcs ? '%ticket_pilot.pipeline_ref%' : '',
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
            new Reference(PipelineTriggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            new Reference(JsonlRunStore::class),
        ]);
        $launch->addTag('controller.service_arguments');
        $launch->setPublic(true);
        $container->setDefinition(DashboardLaunchController::class, $launch);

        $detail = new Definition(DashboardTicketController::class, [
            new Reference(JsonlRunStore::class),
            new Reference(DashboardRenderer::class),
            new Reference('router'),
        ]);
        $detail->addTag('controller.service_arguments');
        $detail->setPublic(true);
        $container->setDefinition(DashboardTicketController::class, $detail);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function attachmentsDir(array $config, string $projectDir): string
    {
        return $config['attachments']['enabled']
            ? rtrim($projectDir, '/').'/'.ltrim($config['attachments']['dir'], '/')
            : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerAttachments(ContainerBuilder $container, array $config): void
    {
        if (!$config['attachments']['enabled']) {
            return;
        }

        $a = $config['attachments'];
        $container->setDefinition(DocumentConverter::class, new Definition(DocumentConverter::class, [
            $a['soffice_binary'],
            $a['timeout'],
            new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setDefinition(AttachmentCollector::class, new Definition(AttachmentCollector::class, [
            new Reference(DocumentConverter::class),
            $a['convert_documents'],
        ]));
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
        $screenshotDir = $base.'/'.ltrim($review['screenshot_dir'], '/');

        $container->setDefinition(ReviewUrlResolver::class, new Definition(ReviewUrlResolver::class, [$review['url_pattern']]));

        if ('agent' === $review['driver']) {
            $this->registerAgentReview($container, $review, $base, $screenshotDir, $config['prompt']['language']);

            return;
        }

        $container->setDefinition(RecipeFactory::class, new Definition(RecipeFactory::class));
        $container->setDefinition(RecipeRepository::class, new Definition(RecipeRepository::class, [
            $base.'/'.ltrim($review['recipes_dir'], '/'),
            new Reference(RecipeFactory::class),
        ]));
        $container->setDefinition(RecipeExecutor::class, new Definition(RecipeExecutor::class, [
            $screenshotDir,
            $review['wait_timeout'],
        ]));
        $browserOptions = ['headless' => true];
        if ($review['no_sandbox']) {
            $browserOptions['noSandbox'] = true;
        }
        $container->setDefinition(ChromeRecipeRunner::class, new Definition(ChromeRecipeRunner::class, [
            new Reference(RecipeExecutor::class),
            $review['chrome_binary'],
            $browserOptions,
        ]));
        $container->setAlias(RecipeRunnerInterface::class, ChromeRecipeRunner::class);

        $this->registerCommand($container, ReviewCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(ReviewUrlResolver::class),
            'recipe',
            '%ticket_pilot.default_source%',
            new Reference(RecipeRepository::class),
            new Reference(RecipeRunnerInterface::class),
            null,
            new Reference(RunStoreInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
    }

    /**
     * Wires the agent-driven review: a coding agent drives a real browser (via its
     * own tools/MCP) from the ticket and merge request context, then reports a verdict.
     *
     * @param array<string, mixed> $review
     */
    private function registerAgentReview(ContainerBuilder $container, array $review, string $base, string $screenshotDir, string $language): void
    {
        $rulesFile = '' !== $review['rules_file'] ? $base.'/'.ltrim($review['rules_file'], '/') : '';

        $container->setDefinition(AgentReviewPromptBuilder::class, new Definition(AgentReviewPromptBuilder::class, [
            $language,
            $rulesFile,
            $screenshotDir,
            $review['summary_start_marker'],
            $review['summary_end_marker'],
        ]));

        $agentName = '' !== $review['agent'] ? $review['agent'] : '%ticket_pilot.default_agent%';
        $mergeRequestReader = $review['merge_request_context']
            ? new Reference(MergeRequestReaderInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE)
            : null;

        $reportRenderer = null;
        if ($review['report']['enabled']) {
            $container->setDefinition(ReviewReportRenderer::class, new Definition(ReviewReportRenderer::class, [
                $screenshotDir,
                $review['report']['soffice_binary'],
                $review['report']['timeout'],
                new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ]));
            $reportRenderer = new Reference(ReviewReportRenderer::class);
        }

        $container->setDefinition(AgentReviewRunner::class, new Definition(AgentReviewRunner::class, [
            new Reference(AgentRegistry::class),
            new Reference(AgentReviewPromptBuilder::class),
            $agentName,
            $screenshotDir,
            $review['login'],
            $review['password'],
            $review['summary_start_marker'],
            $review['summary_end_marker'],
            $mergeRequestReader,
            $reportRenderer,
        ]));

        $this->registerCommand($container, ReviewCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(ReviewUrlResolver::class),
            'agent',
            '%ticket_pilot.default_source%',
            null,
            null,
            new Reference(AgentReviewRunner::class),
            new Reference(RunStoreInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
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
        $reviewRecipePath = ($config['review']['enabled'] && 'recipe' === $config['review']['driver'] && $config['review']['write_recipe'])
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
            $this->attachmentsDir($config, $projectDir),
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

        $configuredModels = [];
        if ($cursor['enabled']) {
            $configuredModels['cursor'] = $cursor['models'];
        }
        if ($claude['enabled']) {
            $configuredModels['claude'] = $claude['models'];
        }
        $container->setDefinition(AgentModelCatalog::class, new Definition(AgentModelCatalog::class, [
            $configuredModels,
            new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]));
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
        // Both providers can read a merge/pull request description (agent review context).
        $container->setAlias(MergeRequestReaderInterface::class, $providerClass);
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
        // The installer only needs the project dir; available before anything is configured.
        $this->registerCommand($container, InstallCommand::class, [$projectDir]);

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
            $this->attachmentsDir($config, $projectDir),
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
            $config['attachments']['enabled'] ? new Reference(AttachmentCollector::class) : null,
        ]));

        $this->registerCommand($container, AutoDevCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(AgentRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(AutoDevRunner::class),
            new Reference(TicketGuard::class),
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
            new Reference(RunStoreInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $this->registerCommand($container, CreateMergeRequestCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(MergeRequestFactory::class),
            new Reference(VcsProviderInterface::class),
            '%ticket_pilot.default_source%',
        ]);

        $prompt = $config['prompt'];
        $container->setDefinition(IteratePromptBuilder::class, new Definition(IteratePromptBuilder::class, [
            $prompt['language'],
            $prompt['quality_commands'],
            $prompt['summary_start_marker'],
            $prompt['summary_end_marker'],
            $prompt['extra_instructions'],
        ]));
        $container->setDefinition(IterateRunner::class, new Definition(IterateRunner::class, [
            new Reference(AgentRegistry::class),
            new Reference(IteratePromptBuilder::class),
            new Reference(MergeRequestFactory::class),
            new Reference(GitClient::class),
            new Reference(VcsProviderInterface::class),
            $options,
            $prompt['summary_start_marker'],
            $prompt['summary_end_marker'],
            $config['quality']['enabled'] ? new Reference(QualityGateInterface::class) : null,
            new Reference('lock.factory', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]));
        $this->registerCommand($container, IterateCommand::class, [
            new Reference(TicketSourceRegistry::class),
            new Reference(AgentRegistry::class),
            new Reference(BranchPlanner::class),
            new Reference(IterateRunner::class),
            '%ticket_pilot.default_source%',
            '%ticket_pilot.default_agent%',
            new Reference(RunStoreInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
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
     * Resolves a project-relative or %kernel.project_dir%-prefixed path to an
     * absolute filesystem path.
     */
    private function resolveProjectPath(string $path, string $projectDir): string
    {
        $path = str_replace('%kernel.project_dir%', $projectDir, $path);
        if ('' !== $path && ('/' === $path[0] || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $path))) {
            return $path;
        }

        return rtrim($projectDir, '/').'/'.ltrim($path, '/');
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
