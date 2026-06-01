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

        return $treeBuilder;
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
            ->end()->end()
            ->arrayNode('claude')->canBeDisabled()->children()
                ->scalarNode('binary')->defaultValue('claude')->end()
                ->booleanNode('skip_permissions')->defaultTrue()->end()
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
        ->end()->end();
    }

    private function addQualitySection(\Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root): void
    {
        $root->children()->arrayNode('quality')->canBeEnabled()->children()
            ->integerNode('timeout')->defaultValue(300)->min(1)->end()
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
