# Ticket Pilot Bundle

[![CI](https://github.com/thebenbenj/ticket-pilot-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/thebenbenj/ticket-pilot-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/thebenbenj/ticket-pilot-bundle)](https://packagist.org/packages/thebenbenj/ticket-pilot-bundle)
[![License](https://img.shields.io/packagist/l/thebenbenj/ticket-pilot-bundle)](LICENSE)

**Your tickets, shipped as merge requests — automatically, on your own infra.**

Ticket Pilot is a self-hosted Symfony bundle that closes the loop from **ticket** to
**merge/pull request**: it picks up a ticket (Jira, Sentry, GitHub Issues), creates the
right branch, drives a **coding agent** (Cursor CLI, Claude Code) to implement it, runs
your quality checks, then opens a ready-to-review MR/PR on GitLab or GitHub.

Run it from the CLI, expose it as an HTTP endpoint, or let it run **unattended** on a
schedule — it works through the boring tickets while your team focuses on the hard ones.
No SaaS, your keys, your runners.

### Why Ticket Pilot

- 🎫 **Multi-source** — Jira, Sentry and GitHub Issues out of the box (one interface to add yours).
- 🤖 **Multi-agent** — Cursor CLI and Claude Code, swappable; bring your own.
- 🔀 **Multi-VCS** — GitLab merge requests and GitHub pull requests, including draft MRs.
- 🧠 **Smart branching** — features off `develop`, hotfixes off `main`, release branches from the ticket's fix version.
- ✅ **Quality gate** — runs your `make check` / tests before pushing; abort or open a flagged draft on failure.
- 🔐 **Hardened** — untrusted ticket content is fenced against prompt injection; optional trusted-reporters allowlist; failed runs clean up their branch.
- 🪝 **Extensible & observable** — tagged-service registries, `TicketProcessed` / `TicketFailed` events, a configurable prompt.
- 🏠 **Self-hosted & open source (MIT)** — no third-party service, your tokens never leave your infra.

Everything is built around small interfaces, so you can plug in your own ticket source,
VCS provider, coding agent or prompt without touching the core.

> ⚠️ The bundle runs a coding agent that **writes to your repository and pushes
> branches**. Run it in CI or a disposable working copy, never against an
> environment you cannot throw away. See [SECURITY.md](SECURITY.md).

## Requirements

- PHP ≥ 8.2
- Symfony 6.4 LTS or 7.x
- `symfony/http-client` (for the bundled Jira / Sentry / GitHub / GitLab integrations)
- A git binary and the CLI of the agent you enable (`agent` for Cursor, `claude`
  for Claude Code) available in the runtime that executes the pipeline

## Installation

```bash
composer require thebenbenj/ticket-pilot-bundle
```

Enable the bundle manually (until the [Flex recipe](recipe/) is published to
`symfony/recipes-contrib`, which will do this and drop a starter config for you):

```php
// config/bundles.php
return [
    // ...
    TheBenBenJ\TicketPilotBundle\TicketPilotBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/ticket_pilot.yaml`. Sources, the VCS provider and the
quality gate are **opt-in**; agents are on by default. Use environment variables
for every secret.

```yaml
ticket_pilot:
    default_source: jira
    default_agent: claude

    sources:
        jira:
            enabled: true
            base_uri: '%env(JIRA_URL)%'
            email: '%env(JIRA_EMAIL)%'
            token: '%env(JIRA_TOKEN)%'
            project: '%env(JIRA_PROJECT)%'
            pending_label: 'IA'          # JQL label that flags a ticket as ready
            pending_status: 'To Do'      # JQL status of pending tickets
        sentry:
            enabled: true
            base_uri: '%env(SENTRY_URL)%'
            token: '%env(SENTRY_TOKEN)%'
            organization: '%env(SENTRY_ORG)%'
            project: '%env(SENTRY_PROJECT)%'
        github:
            enabled: false               # GitHub Issues as a ticket source
            token: '%env(GITHUB_TOKEN)%'
            repository: '%env(GITHUB_REPOSITORY)%'   # "owner/repo"
            pending_label: 'ia'          # open issues with this label are pending
            bug_label: 'bug'             # issues with this label use the hotfix flow

    # Enable EXACTLY ONE VCS provider (gitlab or github).
    vcs:
        gitlab:
            enabled: true
            base_uri: '%env(GITLAB_URL)%'
            token: '%env(GITLAB_TOKEN)%'
            project_path: '%env(GITLAB_PROJECT_PATH)%'   # e.g. "group/project"
            pipeline_ref: 'main'
        github:
            enabled: false
            # base_uri: 'https://<host>/api/v3'        # for GitHub Enterprise Server
            token: '%env(GITHUB_TOKEN)%'
            repository: '%env(GITHUB_REPOSITORY)%'       # "owner/repo"
            dispatch_event_type: 'ticket-pilot'          # repository_dispatch event
            pipeline_ref: 'main'

    agents:
        cursor:
            binary: agent
        claude:
            binary: claude
            skip_permissions: true

    prompt:
        language: 'English'
        quality_commands: ['make check', 'make test']
        extra_instructions: |
            Never run destructive database or build commands.
        # Read at run time and injected as trusted guidelines (globs supported):
        convention_files: ['CLAUDE.md', '.cursor/rules/*.md']

    branching:
        feature_base: develop
        hotfix_base: main
        release_branch_pattern: 'release/RC-{version}'   # {version} = ticket fix version
        bug_types: ['bug', 'anomalie', 'defect']

    merge_request:
        draft: true                  # open every MR/PR as a draft (a proposal, never auto-mergeable)

    # When enabled, these commands run AFTER the agent and BEFORE push.
    quality:
        enabled: true
        on_failure: draft            # 'abort' = no push / no MR ; 'draft' = push + draft MR flagged with the errors
        commands:
            check: ['make', 'check']
            test: ['make', 'test']

    commit:
        exclude_paths:
            - config/packages/ticket_pilot.yaml
            - .env
            - .gitlab-ci.yml

    security:
        # When non-empty, auto-pickup (no --ticket) only processes tickets whose
        # reporter is listed — mitigates ticket-driven prompt injection.
        trusted_reporters: ['alice@example.com', 'bob@example.com']
```

Full reference:

```bash
php bin/console config:dump-reference ticket_pilot
```

## Usage

```bash
# List the pending tickets of a source
php bin/console ia:tickets:list --source=jira

# Print the prompt that would be sent to the agent (debugging)
php bin/console ia:prompt --ticket=PROJ-1234

# Full run: branch, agent, commit, push, merge request
php bin/console ia:auto-dev --ticket=PROJ-1234 --agent=claude
php bin/console ia:auto-dev --source=sentry --limit=3      # batch from a source
php bin/console ia:auto-dev --dry-run                       # preview only
```

### HTTP trigger (optional)

When a VCS provider exposing pipelines is enabled, you can trigger a CI pipeline
over HTTP. Import the route:

```yaml
# config/routes/ticket_pilot.yaml
ticket_pilot:
    resource: '@TicketPilotBundle/Resources/config/routes.php'
```

```
GET /ia/auto-dev?ticket=PROJ-1234&source=jira&agent=claude
```

> Protect this route with your firewall — it starts a pipeline.

With **GitLab** this calls the pipelines API; with **GitHub** it sends a
`repository_dispatch` event (the configured `dispatch_event_type`) carrying the
auto-dev variables as `client_payload`. A workflow then runs the command:

```yaml
# .github/workflows/ticket-pilot.yml
on:
  repository_dispatch:
    types: [ticket-pilot]
jobs:
  auto-dev:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - run: php bin/console ia:auto-dev
              --ticket="${{ github.event.client_payload.IA_TICKET }}"
              --source="${{ github.event.client_payload.IA_SOURCE }}"
              --agent="${{ github.event.client_payload.IA_AGENT }}"
```

## Running it unattended (server / CI / cron)

The point of Ticket Pilot is to run **without a human in the loop**: on a schedule, it
fetches the pending tickets of a source and ships an MR/PR for each. The agent edits the
repo and pushes, so always run it in a **disposable, isolated job** (ephemeral CI runner
or container) with a **minimally-scoped token**, and gate it with `security.trusted_reporters`
so only tickets from known reporters are picked up automatically.

The unattended command is simply the batch form (no `--ticket`):

```bash
php bin/console ia:auto-dev --source=jira --limit=5 --agent=claude
```

**GitLab — scheduled pipeline.** Add a job that installs the agent CLI and runs the
command, then create a *pipeline schedule* (Settings → CI/CD → Pipeline schedules):

```yaml
# .gitlab-ci.yml
ticket-pilot:
  stage: build
  rules:
    - if: '$CI_PIPELINE_SOURCE == "schedule"'   # only on the schedule
  script:
    - curl -fsSL https://cursor.com/install | bash   # or install Claude Code
    - php bin/console ia:auto-dev --source=jira --limit=5
```

**GitHub Actions — cron.** Same idea with a `schedule` trigger (and/or the
`repository_dispatch` workflow shown above for on-demand runs):

```yaml
# .github/workflows/ticket-pilot.yml
on:
  schedule:
    - cron: '0 7 * * 1-5'   # every weekday at 07:00 UTC
jobs:
  pilot:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      # ... setup PHP, install deps and the agent CLI ...
      - run: php bin/console ia:auto-dev --source=github --limit=5
```

**Plain server — cron / systemd timer.** On a throwaway worker that has the repo, the
agent CLI and the tokens:

```cron
# every 30 min, pick up to 3 pending tickets
*/30 * * * * cd /srv/app && php bin/console ia:auto-dev --source=jira --limit=3 >> var/log/ticket-pilot.log 2>&1
```

> Concurrency: configure `framework.lock` so two overlapping schedules never process the
> same ticket twice — Ticket Pilot takes a per-ticket lock and reports duplicates as skipped.

> Tip: listen to the `TicketProcessed` / `TicketFailed` events (see [Events](#events)) to
> post results to Slack and to alert on failures from these unattended runs.

## Browser review (`ia:review`)

While implementing a ticket, the agent also writes a **test recipe** —
`.ticket-pilot/recipes/<key>.yaml` — describing how to verify the feature in a browser.
Once the branch is deployed (e.g. a review app), replay it:

```bash
php bin/console ia:review LYSI-2098                 # URL from the configured pattern
php bin/console ia:review LYSI-2098 --url=https://staging.example.com
```

It drives **headless Chromium** (chrome-php/chrome), runs the steps, takes screenshots,
and **posts the result back to the ticket** (Jira/GitHub comment). Recipe format:

```yaml
description: The invoice payment delay can be edited
steps:
    - { action: visit, target: "/admin/invoice/123" }
    - { action: fill, target: "#delay", value: "30" }
    - { action: click, target: "button[type=submit]" }
    - { action: wait_for, target: ".alert" }
    - { action: assert_selector, target: ".alert-success" }
    - { action: assert_see, value: "Delay updated" }
    - { action: screenshot, value: "result" }
```

```yaml
# config/packages/ticket_pilot.yaml
ticket_pilot:
    review:
        enabled: true
        url_pattern: 'https://{branch_slug}.review.example.com'   # or pass --url
        # recipes_dir: '.ticket-pilot/recipes'
        # chrome_binary: '/usr/bin/chromium'   # empty = auto-detect
        # screenshot_dir: 'var/ticket-pilot/screenshots'
```

> Requires `composer require chrome-php/chrome` and a Chromium/Chrome binary in the runtime.
> The step logic is engine-agnostic (`RecipeRunnerInterface` / `BrowserPageInterface`), so you
> can plug in another browser engine.

## Extending

Jira, Sentry and GitHub Issues sources, GitLab and GitHub providers, and the
Cursor and Claude agents ship with the bundle. To add your own, register a
service implementing one of the contracts and tag it; the registries pick it up
automatically.

```php
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;

#[AutoconfigureTag('ticket_pilot.ticket_source')]
final class LinearTicketSource implements TicketSourceInterface
{
    public function getName(): string { return 'linear'; }
    public function fetchPending(int $limit = 1): array { /* ... */ }
    public function fetchOne(string $key): \TheBenBenJ\TicketPilotBundle\Model\Ticket { /* ... */ }
}
```

| Contract | Bundled | Purpose | Tag |
|----------|---------|---------|-----|
| `TicketSourceInterface` | Jira, Sentry, GitHub Issues | Provide tickets | `ticket_pilot.ticket_source` |
| `CodingAgentInterface` | Cursor, Claude Code | Drive a coding agent | `ticket_pilot.agent` |
| `VcsProviderInterface` | GitLab, GitHub | Open merge/pull requests | — (aliased) |
| `PipelineTriggerInterface` | GitLab, GitHub | Trigger a CI pipeline | — (aliased) |
| `PromptBuilderInterface` | `DefaultPromptBuilder` | Build the agent prompt | — (decorate it) |
| `QualityGateInterface` | `CommandQualityGate` | Run linters / tests | — (aliased) |

To customize the prompt, decorate the `PromptBuilderInterface` alias or replace
`DefaultPromptBuilder`.

### Events

`AutoDevRunner` dispatches lifecycle events (when an event dispatcher is available) so you
can notify, measure or trigger follow-ups without touching the core:

| Event | When |
|-------|------|
| `TicketProcessedEvent` | a ticket was implemented and its MR/PR opened (carries the `AutoDevOutcome`) |
| `TicketFailedEvent` | processing failed, after branch cleanup, before the error is rethrown |

```php
#[AsEventListener]
final class NotifySlack
{
    public function __invoke(TicketProcessedEvent $event): void
    {
        // $event->ticket, $event->outcome->mergeRequest->url
    }
}
```

## How it works

```
TicketSource ─► BranchPlanner ─► GitClient.createBranch
                                       │
                                 PromptBuilder
                                       │
                                  CodingAgent.run        (edits the working tree)
                                       │
                                 QualityGate.verify      (optional — abort on failure)
                                       │
                              GitClient.commitAndPush
                                       │
                          MergeRequestFactory + VcsProvider.createMergeRequest
```

`AutoDevRunner` is the reusable, side-effecting core; the console commands and the
HTTP controller are thin layers on top of it.

## Security

The agent edits your repo and pushes with a write token, and its prompt is built from
**attacker-controllable** ticket text — so **prompt injection** is the headline risk.
The bundle mitigates it (untrusted-data fencing in the prompt, a `trusted_reporters`
allowlist on auto-pickup, draft MRs, a quality gate, commit exclude-paths), but you must
run it in a **disposable, isolated environment** with a **minimally-scoped token** and
**human review** of every MR. Read [SECURITY.md](SECURITY.md) before production use.

## Testing

```bash
composer install
composer test     # phpunit
composer stan     # phpstan (level 8)
composer cs       # php-cs-fixer (dry-run)
```

## License

Released under the [MIT License](LICENSE).
