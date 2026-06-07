<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive installer: asks the structural choices and writes a clean,
 * commented config/packages/ticket_pilot.yaml (secrets as %env(...)% placeholders).
 *
 * The full option reference lives in the README; this command only covers the
 * decisions that shape the file, with sensible defaults for everything else.
 */
#[AsCommand(
    name: 'ia:install',
    description: 'Generate config/packages/ticket_pilot.yaml interactively',
)]
final class InstallCommand extends Command
{
    /** @var array<string, list<string>> source => env var names to set */
    private const SOURCE_ENV = [
        'jira' => ['JIRA_URL', 'JIRA_EMAIL', 'JIRA_TOKEN', 'JIRA_PROJECT'],
        'sentry' => ['SENTRY_TOKEN', 'SENTRY_ORG', 'SENTRY_PROJECT'],
        'github' => ['GITHUB_TOKEN', 'GITHUB_REPOSITORY'],
    ];

    /** @var array<string, list<string>> vcs => env var names to set */
    private const VCS_ENV = [
        'gitlab' => ['GITLAB_URL', 'GITLAB_TOKEN', 'GITLAB_PROJECT_PATH'],
        'github' => ['GITHUB_TOKEN', 'GITHUB_REPOSITORY'],
    ];

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing config file')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Target file (default: config/packages/ticket_pilot.yaml)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Ticket Pilot — configuration');

        $pathOption = $input->getOption('path');
        $target = \is_string($pathOption) && '' !== $pathOption
            ? $pathOption
            : rtrim($this->projectDir, '/').'/config/packages/ticket_pilot.yaml';

        if (is_file($target) && !$input->getOption('force')) {
            if (!$io->confirm(\sprintf('%s already exists. Overwrite?', $target), false)) {
                $io->warning('Aborted. Re-run with --force to overwrite.');

                return Command::SUCCESS;
            }
        }

        // --- General -----------------------------------------------------------
        $io->section('General');
        $language = $io->ask('Language the agent must write in (code, tests, output)', 'English');
        $language = \is_string($language) ? $language : 'English';
        $agent = $io->choice('Default coding agent', ['cursor', 'claude'], 'cursor');

        // --- Ticket source -----------------------------------------------------
        $io->section('Ticket source');
        $source = $io->choice('Where do tickets come from?', ['jira', 'sentry', 'github'], 'jira');

        // --- VCS ---------------------------------------------------------------
        $io->section('Version control / merge requests');
        $vcs = $io->choice('Where are merge/pull requests opened?', ['gitlab', 'github'], 'gitlab');
        $pipelineRef = $io->ask('Branch the CI pipeline is triggered on (pipeline_ref)', 'main');
        $pipelineRef = \is_string($pipelineRef) ? $pipelineRef : 'main';

        // --- Branching ---------------------------------------------------------
        $io->section('Branching');
        $featureBase = (string) ($io->ask('Base branch for features', 'develop') ?? 'develop');
        $hotfixBase = (string) ($io->ask('Base branch for hotfixes', 'main') ?? 'main');

        // --- Merge request -----------------------------------------------------
        $io->section('Merge request');
        $commitTpl = (string) ($io->ask('Commit message template ({key}, {title} placeholders)', '[{key}] {title}') ?? '[{key}] {title}');
        $draft = $io->confirm('Open every merge/pull request as a draft?', false);

        // --- Quality gate ------------------------------------------------------
        $io->section('Quality gate');
        $quality = $io->confirm('Run quality checks after the agent and before pushing?', true);
        $qualityOnFailure = $quality ? $io->choice('On failure', ['abort', 'draft'], 'draft') : 'abort';

        // --- Review ------------------------------------------------------------
        $io->section('Browser review');
        $review = $io->confirm('Enable the browser review (ia:review)?', false);
        $reviewDriver = 'recipe';
        $reviewUrlPattern = '';
        if ($review) {
            $reviewDriver = $io->choice('Review driver', ['recipe', 'agent'], 'agent');
            $reviewUrlPattern = (string) ($io->ask('Deployed app URL pattern ({branch_slug} placeholder), or empty to pass --url', '') ?? '');
        }

        // --- Tracking ----------------------------------------------------------
        $io->section('Run tracking & dashboard');
        $tracking = $io->confirm('Record runs and expose the /ia/dashboard?', false);

        $yaml = $this->buildYaml(
            $source,
            $vcs,
            $agent,
            $language,
            $pipelineRef,
            $featureBase,
            $hotfixBase,
            $commitTpl,
            $draft,
            $quality,
            $qualityOnFailure,
            $review,
            $reviewDriver,
            $reviewUrlPattern,
            $tracking,
        );

        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $io->error(\sprintf('Cannot create directory %s', $dir));

            return Command::FAILURE;
        }
        if (false === file_put_contents($target, $yaml)) {
            $io->error(\sprintf('Cannot write %s', $target));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote %s', $target));
        $this->printNextSteps($io, $source, $vcs, $review, $tracking);

        return Command::SUCCESS;
    }

    private function buildYaml(
        string $source,
        string $vcs,
        string $agent,
        string $language,
        string $pipelineRef,
        string $featureBase,
        string $hotfixBase,
        string $commitTpl,
        bool $draft,
        bool $quality,
        string $qualityOnFailure,
        bool $review,
        string $reviewDriver,
        string $reviewUrlPattern,
        bool $tracking,
    ): string {
        $b = "# Generated by `php bin/console ia:install`. Full reference:\n";
        $b .= "#   php bin/console config:dump-reference ticket_pilot\n";
        $b .= "# Every option is documented in the bundle README (Configuration reference).\n";
        $b .= "ticket_pilot:\n";
        $b .= \sprintf("    default_source: %s\n", $source);
        $b .= \sprintf("    default_agent: %s\n\n", $agent);

        $b .= "    sources:\n".$this->sourceYaml($source)."\n";
        $b .= "    vcs:\n".$this->vcsYaml($vcs, $pipelineRef)."\n";

        $b .= "    agents:\n";
        $b .= "        cursor: { binary: agent }\n";
        $b .= "        claude: { binary: claude, skip_permissions: true }\n\n";

        $b .= "    prompt:\n";
        $b .= \sprintf("        language: '%s'\n", $language);
        $b .= "        quality_commands: ['make check', 'make test']\n";
        $b .= "        # convention_files: ['CLAUDE.md', '.cursor/rules/*.md']\n";
        $b .= "        # extra_instructions: |\n";
        $b .= "        #     Never run destructive database or build commands.\n\n";

        $b .= "    branching:\n";
        $b .= \sprintf("        feature_base: %s\n", $featureBase);
        $b .= \sprintf("        hotfix_base: %s\n", $hotfixBase);
        $b .= "        # bug_types: ['bug', 'anomalie', 'defect']\n\n";

        $b .= "    merge_request:\n";
        $b .= \sprintf("        commit_message_template: '%s'\n", $commitTpl);
        $b .= \sprintf("        draft: %s\n\n", $draft ? 'true' : 'false');

        if ($quality) {
            $b .= "    quality:\n";
            $b .= "        enabled: true\n";
            $b .= \sprintf("        on_failure: %s\n", $qualityOnFailure);
            $b .= "        commands:\n";
            $b .= "            check: ['make', 'check']\n";
            $b .= "            test: ['make', 'test']\n\n";
        }

        if ($review) {
            $b .= "    review:\n";
            $b .= "        enabled: true\n";
            $b .= \sprintf("        driver: %s\n", $reviewDriver);
            if ('' !== $reviewUrlPattern) {
                $b .= \sprintf("        url_pattern: '%s'\n", $reviewUrlPattern);
            }
            if ('agent' === $reviewDriver) {
                $b .= "        rules_file: '.ticket-pilot/review-context.md'\n";
                $b .= "        login: '%env(IA_REVIEW_LOGIN)%'\n";
                $b .= "        password: '%env(IA_REVIEW_PASSWORD)%'\n";
            } else {
                $b .= "        no_sandbox: true   # required in most Docker containers / as root\n";
            }
            $b .= "\n";
        }

        if ($tracking) {
            $b .= "    tracking:\n";
            $b .= "        enabled: true\n";
            $b .= "        path: '%kernel.project_dir%/var/ticket-pilot/runs.jsonl'\n";
            $b .= "        dashboard: true\n";
            $b .= "        # In CI only, forward runs to the dashboard env:\n";
            $b .= "        remote_url: '%env(default::IA_RUNS_REMOTE_URL)%'\n";
            $b .= "        ingest_token: '%env(default::IA_RUNS_TOKEN)%'\n\n";
        }

        $b .= "    commit:\n";
        $b .= "        exclude_paths:\n";
        $b .= "            - config/packages/ticket_pilot.yaml\n";
        $b .= "            - .env\n";

        return $b;
    }

    private function sourceYaml(string $source): string
    {
        return match ($source) {
            'sentry' => "        sentry:\n"
                ."            enabled: true\n"
                ."            base_uri: 'https://sentry.io'\n"
                ."            token: '%env(SENTRY_TOKEN)%'\n"
                ."            organization: '%env(SENTRY_ORG)%'\n"
                ."            project: '%env(SENTRY_PROJECT)%'\n",
            'github' => "        github:\n"
                ."            enabled: true\n"
                ."            token: '%env(GITHUB_TOKEN)%'\n"
                ."            repository: '%env(GITHUB_REPOSITORY)%'   # owner/repo\n"
                ."            pending_label: 'ia'\n"
                ."            bug_label: 'bug'\n",
            default => "        jira:\n"
                ."            enabled: true\n"
                ."            base_uri: '%env(JIRA_URL)%'\n"
                ."            email: '%env(JIRA_EMAIL)%'\n"
                ."            token: '%env(JIRA_TOKEN)%'\n"
                ."            project: '%env(JIRA_PROJECT)%'\n"
                ."            pending_label: 'IA'\n"
                ."            pending_status: 'To Do'\n",
        };
    }

    private function vcsYaml(string $vcs, string $pipelineRef): string
    {
        if ('github' === $vcs) {
            return "        github:\n"
                ."            enabled: true\n"
                ."            token: '%env(GITHUB_TOKEN)%'\n"
                ."            repository: '%env(GITHUB_REPOSITORY)%'   # owner/repo\n"
                ."            dispatch_event_type: 'ticket-pilot'\n"
                .\sprintf("            pipeline_ref: '%s'\n", $pipelineRef);
        }

        return "        gitlab:\n"
            ."            enabled: true\n"
            ."            base_uri: '%env(GITLAB_URL)%'\n"
            ."            token: '%env(GITLAB_TOKEN)%'\n"
            ."            project_path: '%env(GITLAB_PROJECT_PATH)%'   # group/project\n"
            .\sprintf("            pipeline_ref: '%s'\n", $pipelineRef);
    }

    private function printNextSteps(SymfonyStyle $io, string $source, string $vcs, bool $review, bool $tracking): void
    {
        $env = array_values(array_unique(array_merge(self::SOURCE_ENV[$source] ?? [], self::VCS_ENV[$vcs] ?? [])));
        if ($review) {
            $env[] = 'IA_REVIEW_LOGIN';
            $env[] = 'IA_REVIEW_PASSWORD';
        }

        $io->section('Next steps');
        $io->listing([
            'Register the bundle in config/bundles.php: TheBenBenJ\\TicketPilotBundle\\TicketPilotBundle::class => [\'all\' => true]',
            'Set these environment variables (e.g. in .env.local): '.implode(', ', $env),
            ($review || $tracking) ? 'Import the routes (HTTP trigger / dashboard): see the README "HTTP" section.' : 'No HTTP routes needed.',
            'Verify: php bin/console config:dump-reference ticket_pilot',
        ]);
    }
}
