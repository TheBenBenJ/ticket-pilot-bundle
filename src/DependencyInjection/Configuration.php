<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ticket_pilot');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('project_dir')
                    ->defaultValue('%kernel.project_dir%')
                    ->info('Working directory git, the agent and the quality gate operate in.')
                ->end()
                ->scalarNode('default_source')->defaultValue('jira')->end()
                ->scalarNode('default_agent')->defaultValue('cursor')->end()
                ->integerNode('agent_timeout')->defaultValue(3600)->min(1)->end()
                ->integerNode('git_timeout')->defaultValue(120)->min(1)
                    ->info('Timeout (seconds) for each git command (fetch, pull, push, …).')
                ->end()
                ->booleanNode('cleanup_branch_on_failure')->defaultTrue()
                    ->info('Delete the ticket branch (local, and remote if pushed) when a run fails.')
                ->end()
            ->end()
        ;

        $this->addSourcesSection($root);
        $this->addVcsSection($root);
        $this->addAgentsSection($root);
        $this->addPromptSection($root);
        $this->addBranchingSection($root);
        $this->addMergeRequestSection($root);
        $this->addQualitySection($root);
        $this->addCommitSection($root);
        $this->addSecuritySection($root);
        $this->addHttpSection($root);
        $this->addReviewSection($root);
        $this->addAttachmentsSection($root);
        $this->addTrackingSection($root);

        return $treeBuilder;
    }

    private function addTrackingSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('tracking')->canBeEnabled()->children()
            ->scalarNode('path')->defaultValue('var/ticket-pilot/runs.jsonl')
                ->info('Canonical JSONL file (on the env that serves the dashboard). Relative paths are resolved from the project dir.')
            ->end()
            ->booleanNode('dashboard')->defaultTrue()
                ->info('Register the HTTP dashboard controller (the route is still imported manually). Lists the runs and can launch new ones when a VCS provider exposing pipelines is enabled.')
            ->end()
            ->scalarNode('remote_url')->defaultValue('')
                ->info('When set, runs are POSTed to this ingest URL (e.g. https://host/ia/runs) instead of written locally. Set it in throw-away CI containers so their runs land on the env that owns the canonical file; leave empty on that env.')
            ->end()
            ->scalarNode('ingest_token')->defaultValue('')
                ->info('Shared secret guarding the ingest endpoint (POST /ia/runs). The same value must be set on the dashboard env (to verify) and in CI (to send). Empty disables ingestion (endpoint returns 401).')
            ->end()
            ->scalarNode('screenshots_dir')->defaultValue('%kernel.project_dir%/public/ticket-pilot/screenshots')
                ->info('Where the dashboard env saves screenshots received through the ingest (must be web-served — under public/).')
            ->end()
            ->scalarNode('screenshots_base_url')->defaultValue('/ticket-pilot/screenshots')
                ->info('Public URL prefix the saved screenshots are served from (matches screenshots_dir).')
            ->end()
        ->end()->end();
    }

    private function addAttachmentsSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('attachments')->canBeEnabled()->children()
            ->scalarNode('dir')->defaultValue('var/ticket-pilot/attachments')
                ->info('Base dir (relative to the project) where ticket attachments are downloaded, per ticket.')
            ->end()
            ->booleanNode('convert_documents')->defaultTrue()
                ->info('Convert office documents (doc/docx/odt/rtf) to PDF so the agent can read them.')
            ->end()
            ->scalarNode('soffice_binary')->defaultValue('soffice')
                ->info('LibreOffice binary used for the conversion.')
            ->end()
            ->integerNode('timeout')->defaultValue(120)->min(1)->end()
        ->end()->end();
    }

    private function addReviewSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('review')->canBeEnabled()->children()
            ->enumNode('driver')
                ->values(['recipe', 'agent'])
                ->defaultValue('recipe')
                ->info('"recipe": replay a YAML recipe in headless Chromium (chrome-php). "agent": a coding agent drives a real browser (via its own tools/MCP), explores freely and reports a verdict.')
            ->end()
            ->scalarNode('recipes_dir')->defaultValue('.ticket-pilot/recipes')
                ->info('[recipe driver] Directory (relative to the project) where the agent writes/reads test recipes.')
            ->end()
            ->scalarNode('url_pattern')->defaultValue('')
                ->info('Base URL pattern with {ticket}/{key}/{branch}/{branch_slug} placeholders; --url overrides it.')
            ->end()
            ->booleanNode('write_recipe')->defaultTrue()
                ->info('[recipe driver] Instruct the agent to author the test recipe during ia:auto-dev.')
            ->end()
            ->scalarNode('chrome_binary')->defaultValue('')
                ->info('[recipe driver] Path to the Chromium/Chrome binary (empty = auto-detect).')
            ->end()
            ->booleanNode('no_sandbox')->defaultFalse()
                ->info('[recipe driver] Launch Chrome with --no-sandbox. Required when running as root or in most Docker containers where the SUID sandbox is not configured.')
            ->end()
            ->scalarNode('screenshot_dir')->defaultValue('var/ticket-pilot/screenshots')->end()
            ->integerNode('wait_timeout')->defaultValue(5000)->min(0)
                ->info('[recipe driver] Timeout (ms) for wait_for steps.')
            ->end()
            ->scalarNode('agent')->defaultValue('')
                ->info('[agent driver] Agent name driving the review (empty = default_agent).')
            ->end()
            ->scalarNode('rules_file')->defaultValue('')
                ->info('[agent driver] Project file (relative) holding the trusted review guidance (login, navigation, test data, what is an error) injected into the prompt.')
            ->end()
            ->scalarNode('login')->defaultValue('')
                ->info('[agent driver] Login handed to the review agent. Use %env(...)%.')
            ->end()
            ->scalarNode('password')->defaultValue('')
                ->info('[agent driver] Password handed to the review agent. Use %env(...)%.')
            ->end()
            ->booleanNode('merge_request_context')->defaultTrue()
                ->info('[agent driver] Fetch the merge/pull request description for the branch and inject it as "what was developed".')
            ->end()
            ->scalarNode('summary_start_marker')->defaultValue('<<<REVIEW_SUMMARY')
                ->info('[agent driver] Opening marker of the verdict block the agent must emit.')
            ->end()
            ->scalarNode('summary_end_marker')->defaultValue('REVIEW_SUMMARY>>>')
                ->info('[agent driver] Closing marker of the verdict block.')
            ->end()
            ->arrayNode('report')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultTrue()
                    ->info('[agent driver] Build a single PDF report (verdict + summary + screenshots) and attach it to the ticket.')
                ->end()
                ->scalarNode('soffice_binary')->defaultValue('soffice')
                    ->info('[agent driver] LibreOffice binary used to render the HTML report to PDF.')
                ->end()
                ->integerNode('timeout')->defaultValue(120)->min(1)->end()
            ->end()->end()
        ->end()->end();
    }

    private function addHttpSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('http')->addDefaultsIfNotSet()->children()
            ->integerNode('max_retries')->defaultValue(3)->min(0)
                ->info('Retries for transient API failures (timeouts, 5xx, 429). 0 disables retrying.')
            ->end()
        ->end()->end();
    }

    private function addSecuritySection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('security')->addDefaultsIfNotSet()->children()
            ->arrayNode('trusted_reporters')
                ->scalarPrototype()->end()
                ->info('When non-empty, auto-pickup (no --ticket) only processes tickets whose reporter is listed. Mitigates ticket-driven prompt injection.')
                ->defaultValue([])
            ->end()
        ->end()->end();
    }

    private function addSourcesSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('sources')->addDefaultsIfNotSet()->children()
            ->arrayNode('jira')->canBeEnabled()->children()
                ->scalarNode('base_uri')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('email')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('project')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('pending_label')->defaultValue('IA')->end()
                ->scalarNode('pending_status')->defaultValue('To Do')->end()
            ->end()->end()
            ->arrayNode('sentry')->canBeEnabled()->children()
                ->scalarNode('base_uri')->defaultValue('https://sentry.io')->end()
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('organization')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('project')->isRequired()->cannotBeEmpty()->end()
            ->end()->end()
            ->arrayNode('github')->canBeEnabled()->children()
                ->scalarNode('base_uri')->defaultValue('https://api.github.com')
                    ->info('Use https://<host>/api/v3 for GitHub Enterprise Server.')
                ->end()
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('repository')->isRequired()->cannotBeEmpty()
                    ->info('Repository in "owner/repo" form.')
                ->end()
                ->scalarNode('pending_label')->defaultValue('ia')
                    ->info('Open issues carrying this label are pending (empty = all open issues).')
                ->end()
                ->scalarNode('bug_label')->defaultValue('bug')
                    ->info('Issues carrying this label are typed as bugs (hotfix flow).')
                ->end()
            ->end()->end()
        ->end()->end();
    }

    private function addVcsSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('vcs')->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['gitlab']['enabled'] ?? false) && ($v['github']['enabled'] ?? false))
                ->thenInvalid('Enable exactly one VCS provider (gitlab or github), not both.')
            ->end()
            ->children()
            ->arrayNode('gitlab')->canBeEnabled()->children()
                ->scalarNode('base_uri')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('project_path')->isRequired()->cannotBeEmpty()
                    ->info('URL-encoded project path, e.g. "group/project".')
                ->end()
                ->scalarNode('pipeline_ref')->defaultValue('main')
                    ->info('Default branch the CI pipeline is triggered on.')
                ->end()
            ->end()->end()
            ->arrayNode('github')->canBeEnabled()->children()
                ->scalarNode('base_uri')->defaultValue('https://api.github.com')
                    ->info('Use https://<host>/api/v3 for GitHub Enterprise Server.')
                ->end()
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('repository')->isRequired()->cannotBeEmpty()
                    ->info('Repository in "owner/repo" form.')
                ->end()
                ->scalarNode('dispatch_event_type')->defaultValue('ticket-pilot')
                    ->info('repository_dispatch event_type a GitHub Actions workflow reacts to.')
                ->end()
                ->scalarNode('pipeline_ref')->defaultValue('main')
                    ->info('Ref carried in the dispatch payload.')
                ->end()
            ->end()->end()
        ->end()->end();
    }

    private function addAgentsSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('agents')->addDefaultsIfNotSet()->children()
            ->arrayNode('cursor')->canBeDisabled()->children()
                ->scalarNode('binary')->defaultValue('agent')->end()
                ->arrayNode('models')
                    ->scalarPrototype()->end()
                    ->defaultValue(['auto'])
                    ->info('Fallback model list for the dashboard when "agent models" cannot be queried live.')
                ->end()
            ->end()->end()
            ->arrayNode('claude')->canBeDisabled()->children()
                ->scalarNode('binary')->defaultValue('claude')->end()
                ->booleanNode('skip_permissions')->defaultTrue()->end()
                ->arrayNode('models')
                    ->scalarPrototype()->end()
                    ->defaultValue(['default', 'best', 'opus', 'sonnet', 'haiku', 'opusplan', 'sonnet[1m]', 'opus[1m]'])
                    ->info('Model aliases accepted by "claude --model" (see Claude Code model-config docs).')
                ->end()
            ->end()->end()
        ->end()->end();
    }

    private function addPromptSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('prompt')->addDefaultsIfNotSet()->children()
            ->scalarNode('language')->defaultValue('English')->end()
            ->arrayNode('quality_commands')
                ->scalarPrototype()->end()
                ->defaultValue(['make check', 'make test'])
                ->info('Commands the agent is told to run before finishing (display only).')
            ->end()
            ->scalarNode('summary_start_marker')->defaultValue('<<<MR_SUMMARY')->end()
            ->scalarNode('summary_end_marker')->defaultValue('MR_SUMMARY>>>')->end()
            ->scalarNode('extra_instructions')->defaultValue('')->end()
            ->arrayNode('convention_files')
                ->scalarPrototype()->end()
                ->defaultValue([])
                ->info('Project files/globs (e.g. CLAUDE.md, .cursor/rules/*.md) read at run time and injected as trusted guidelines.')
            ->end()
        ->end()->end();
    }

    private function addBranchingSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('branching')->addDefaultsIfNotSet()->children()
            ->scalarNode('feature_base')->defaultValue('develop')->end()
            ->scalarNode('hotfix_base')->defaultValue('main')->end()
            ->scalarNode('feature_prefix')->defaultValue('feature')->end()
            ->scalarNode('hotfix_prefix')->defaultValue('hotfix')->end()
            ->scalarNode('release_branch_pattern')->defaultValue('release/RC-{version}')
                ->info('The {version} placeholder is replaced by the ticket fix version.')
            ->end()
            ->arrayNode('bug_types')
                ->scalarPrototype()->end()
                ->defaultValue(['bug', 'anomalie', 'defect'])
                ->info('Lower-cased ticket types routed to the hotfix branch/base.')
            ->end()
        ->end()->end();
    }

    private function addMergeRequestSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('merge_request')->addDefaultsIfNotSet()->children()
            ->scalarNode('commit_message_template')->defaultValue('[{key}] {title}')
                ->info('Supports the {key} and {title} placeholders.')
            ->end()
            ->booleanNode('draft')->defaultFalse()
                ->info('Open every merge/pull request as a draft (a proposal, never auto-mergeable).')
            ->end()
        ->end()->end();
    }

    private function addQualitySection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('quality')->canBeEnabled()->children()
            ->integerNode('timeout')->defaultValue(300)->min(1)->end()
            ->enumNode('on_failure')
                ->values(['abort', 'draft'])
                ->defaultValue('abort')
                ->info('When checks fail: "abort" (no push, no MR) or "draft" (push and open a draft MR flagged with the failing checks).')
            ->end()
            ->arrayNode('commands')
                ->info('Ordered map of label => argv, e.g. { check: [make, check] }.')
                ->useAttributeAsKey('name')
                ->arrayPrototype()->scalarPrototype()->end()->end()
                ->defaultValue(['check' => ['make', 'check'], 'test' => ['make', 'test']])
            ->end()
        ->end()->end();
    }

    private function addCommitSection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('commit')->addDefaultsIfNotSet()->children()
            ->arrayNode('exclude_paths')
                ->scalarPrototype()->end()
                ->info('Paths the agent commit must never include (infra, secrets, the bundle itself).')
                ->defaultValue([])
            ->end()
        ->end()->end();
    }
}
