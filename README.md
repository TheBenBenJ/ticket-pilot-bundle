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

> **Quickest start:** `php bin/console ia:install` generates
> `config/packages/ticket_pilot.yaml` interactively (source, VCS, agent, review,
> tracking, …) and prints the env vars to set. Use `--force` to overwrite.

Or write `config/packages/ticket_pilot.yaml` by hand. Sources, the VCS provider and
the quality gate are **opt-in**; agents are on by default. Use environment variables
for every secret. Every option is listed in the [Configuration reference](#configuration-reference) below.

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

Full machine reference:

```bash
php bin/console config:dump-reference ticket_pilot
```

## Configuration reference

Every option, grouped by section. **Req** = required when its section is enabled.
Secrets should be `%env(...)%` placeholders, never literals.

### Root

| Option | Type | Default | Description |
|---|---|---|---|
| `default_source` | string | `jira` | Source used when `--source` is omitted. |
| `default_agent` | string | `cursor` | Agent used when `--agent` is omitted. |
| `project_dir` | string | `%kernel.project_dir%` | Working dir git, the agent and the quality gate operate in. |
| `agent_timeout` | int (s) | `3600` | Max wall-clock for one agent run. |
| `git_timeout` | int (s) | `120` | Timeout per git command (fetch/pull/push/…). |
| `cleanup_branch_on_failure` | bool | `true` | Delete the ticket branch (local + remote if pushed) when a run fails. |

### `sources` (opt-in, one or more)

**`sources.jira`** — `enabled` (bool, `false`) · `base_uri`*, `email`*, `token`* (Req) · `project`* (Req, project key) · `pending_label` (`IA`) · `pending_status` (`To Do`). JQL pending = `labels = <pending_label> AND status = "<pending_status>"`.

**`sources.sentry`** — `enabled` (bool) · `base_uri` (`https://sentry.io`) · `token`*, `organization`*, `project`* (Req).

**`sources.github`** (issues) — `enabled` (bool) · `base_uri` (`https://api.github.com`, use `.../api/v3` for Enterprise) · `token`*, `repository`* (`owner/repo`, Req) · `pending_label` (`ia`) · `bug_label` (`bug`, → hotfix flow).

### `vcs` (enable EXACTLY one)

**`vcs.gitlab`** — `enabled` (bool) · `base_uri`*, `token`*, `project_path`* (`group/project`, Req) · `pipeline_ref` (`main`, branch the HTTP trigger starts a pipeline on).

**`vcs.github`** — `enabled` (bool) · `base_uri` (`https://api.github.com`) · `token`*, `repository`* (Req) · `dispatch_event_type` (`ticket-pilot`, the `repository_dispatch` type) · `pipeline_ref` (`main`).

### `agents` (on by default)

| Option | Type | Default | Description |
|---|---|---|---|
| `agents.cursor.enabled` | bool | `true` | Cursor CLI agent. |
| `agents.cursor.binary` | string | `agent` | Cursor binary name/path. |
| `agents.claude.enabled` | bool | `true` | Claude Code agent. |
| `agents.claude.binary` | string | `claude` | Claude binary name/path. |
| `agents.claude.skip_permissions` | bool | `true` | Pass `--dangerously-skip-permissions`. |

### `prompt`

| Option | Type | Default | Description |
|---|---|---|---|
| `prompt.language` | string | `English` | Language for the agent's code, tests and output. |
| `prompt.quality_commands` | list | `['make check','make test']` | Commands the agent is told to run before finishing (display only). |
| `prompt.extra_instructions` | string | `''` | Trusted guidelines appended to every prompt. |
| `prompt.convention_files` | list (globs) | `[]` | Files read at run time and injected as trusted rules (e.g. `CLAUDE.md`, `.cursor/rules/*.md`). |
| `prompt.summary_start_marker` / `summary_end_marker` | string | `<<<MR_SUMMARY` / `MR_SUMMARY>>>` | Delimit the MR-description block the agent emits. |

### `branching`

| Option | Type | Default | Description |
|---|---|---|---|
| `branching.feature_base` | string | `develop` | Base branch for features. |
| `branching.hotfix_base` | string | `main` | Base branch for hotfixes (bug types). |
| `branching.feature_prefix` / `hotfix_prefix` | string | `feature` / `hotfix` | Branch name prefixes. |
| `branching.release_branch_pattern` | string | `release/RC-{version}` | `{version}` = ticket fix version. |
| `branching.bug_types` | list | `['bug','anomalie','defect']` | Lower-cased ticket types routed to the hotfix flow. |

### `merge_request`

| Option | Type | Default | Description |
|---|---|---|---|
| `merge_request.commit_message_template` | string | `[{key}] {title}` | Supports `{key}` and `{title}`. |
| `merge_request.draft` | bool | `false` | Open every MR/PR as a draft. |

### `quality` (opt-in)

| Option | Type | Default | Description |
|---|---|---|---|
| `quality.enabled` | bool | `false` | Run checks after the agent, before push. |
| `quality.on_failure` | enum | `abort` | `abort` (no push/MR) or `draft` (push + draft MR flagged with the errors). |
| `quality.timeout` | int (s) | `300` | Timeout per quality command. |
| `quality.commands` | map label→argv | `{check:[make,check], test:[make,test]}` | Ordered checks to run. |

### `review` (opt-in)

| Option | Type | Default | Description |
|---|---|---|---|
| `review.enabled` | bool | `false` | Enable `ia:review`. |
| `review.driver` | enum | `recipe` | `recipe` (replay a YAML recipe in headless Chromium) or `agent` (a coding agent drives a real browser via its MCP and returns a verdict). |
| `review.url_pattern` | string | `''` | Base URL with `{ticket}`/`{key}`/`{branch}`/`{branch_slug}`; `--url` overrides. |
| `review.screenshot_dir` | string | `var/ticket-pilot/screenshots` | Where screenshots are collected. |
| **recipe driver** | | | `recipes_dir` (`.ticket-pilot/recipes`), `write_recipe` (`true`), `chrome_binary` (`''` = auto), `no_sandbox` (`false` — set `true` in Docker/root), `wait_timeout` (ms, `5000`). |
| **agent driver** | | | `agent` (`''` = `default_agent`), `rules_file` (trusted review guidance), `login`/`password` (`%env%`), `merge_request_context` (`true`), `summary_start_marker`/`summary_end_marker` (`<<<REVIEW_SUMMARY`/`REVIEW_SUMMARY>>>`). |
| `review.report.*` | | | `enabled` (`true`), `soffice_binary` (`soffice`), `timeout` (`120`) — PDF report (verdict+summary+screenshots) attached to the ticket. |

### `tracking` (opt-in)

| Option | Type | Default | Description |
|---|---|---|---|
| `tracking.enabled` | bool | `false` | Record runs (`ia:runs`) and expose the dashboard. |
| `tracking.path` | string | `var/ticket-pilot/runs.jsonl` | Canonical JSONL file on the env that serves the dashboard. |
| `tracking.dashboard` | bool | `true` | Register the `/ia/dashboard` controllers (route still imported manually). |
| `tracking.remote_url` | string | `''` | Set **in CI only** → runs are POSTed to this ingest URL instead of written locally. |
| `tracking.ingest_token` | string | `''` | Shared secret for `POST /ia/runs` (set on the dashboard env to verify and in CI to send; empty = ingestion disabled). |
| `tracking.screenshots_dir` | string | `%kernel.project_dir%/public/ticket-pilot/screenshots` | Where the dashboard env saves review screenshots received through the ingest (under `public/`, so they are web-served and shown inline in the timeline). |
| `tracking.screenshots_base_url` | string | `/ticket-pilot/screenshots` | Public URL prefix the saved screenshots are served from. |

### `attachments` (opt-in)

| Option | Type | Default | Description |
|---|---|---|---|
| `attachments.enabled` | bool | `false` | Download ticket attachments for the agent to read. |
| `attachments.dir` | string | `var/ticket-pilot/attachments` | Per-ticket download dir. |
| `attachments.convert_documents` | bool | `true` | Convert office docs (doc/docx/odt/rtf) to PDF. |
| `attachments.soffice_binary` | string | `soffice` | LibreOffice binary for the conversion. |
| `attachments.timeout` | int (s) | `120` | Conversion timeout. |

### `security` & `commit` & `http`

| Option | Type | Default | Description |
|---|---|---|---|
| `security.trusted_reporters` | list | `[]` | When non-empty, auto-pickup (no `--ticket`) only processes tickets whose reporter is listed (anti prompt-injection). |
| `commit.exclude_paths` | list | `[]` | Paths the agent commit must never include (infra, secrets, the bundle config). |
| `http.max_retries` | int | `3` | Retries for transient API failures (timeouts, 5xx, 429); `0` disables. |

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

# Iterate: address review feedback on the ticket's existing branch and push
php bin/console ia:iterate PROJ-1234                        # ticket comments + MR discussion
php bin/console ia:iterate PROJ-1234 --branch=feature/PROJ-1234 --agent=claude

# Review a deployed app (recipe or agent driver); pick the agent per run
php bin/console ia:review PROJ-1234 --url=https://pr-1234.example.com --agent=claude

# List what the bundle has done (needs tracking enabled)
php bin/console ia:runs --type=review --limit=20
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

### Run tracking & dashboard (optional)

Enable `tracking` to record every run (auto-dev, iterate, review) to an append-only
JSONL file, then list them with `ia:runs` or browse the bundled HTML dashboard.

```yaml
ticket_pilot:
    tracking:
        enabled: true
        path: 'var/ticket-pilot/runs.jsonl'   # canonical file on the env that serves the dashboard
        dashboard: true                        # register the /ia/dashboard controller (route imported below)
        # Centralise runs from throw-away CI containers: set these IN CI only, so each run is
        # POSTed to the dashboard env instead of written to the (ephemeral) CI filesystem.
        remote_url: '%env(default::IA_RUNS_REMOTE_URL)%'   # e.g. https://your-host/ia/runs ; empty on the dashboard env
        ingest_token: '%env(default::IA_RUNS_TOKEN)%'      # shared secret, set on both the dashboard env and in CI
```

- `GET /ia/dashboard` — lists the recent runs and, when a pipeline-capable VCS provider is
  enabled, offers forms to **launch** auto-dev / iterate / review (each triggers a CI pipeline
  carrying the `IA_*` variables, incl. `IA_MODE`). Ticket keys link to…
- `GET /ia/dashboard/{ticket}` — the **per-ticket timeline**: every step (dev → iterate →
  review) with the full agent summary and the review screenshots.
- `POST /ia/runs` — ingest endpoint (token-guarded) that CI containers POST their runs to when
  `remote_url` is set, so the dashboard env keeps the single canonical file.

> Protect these routes behind your firewall — the launch forms start pipelines. The store is
> pluggable: alias `RunStoreInterface` to your own implementation (database, …) if needed.

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

Once a ticket's branch is deployed (e.g. a review app), `ia:review` tests it in a browser
and **posts the result back to the ticket** (Jira/GitHub comment). Two drivers are available
(`ticket_pilot.review.driver`):

```bash
php bin/console ia:review LYSI-2098                 # URL from the configured pattern
php bin/console ia:review LYSI-2098 --url=https://staging.example.com
```

### `agent` driver (recommended) — a coding agent drives the browser

The same kind of agent that develops the ticket performs the review: it gets the **business
context** (ticket fields and the merge/pull request description), your **trusted review rules**
(a project file: how to log in, navigate, find test data, what counts as an error), the **login
credentials**, and drives a **real browser through its own tools** (e.g. a [Playwright MCP][mcp]
server) — exploring the app like a tester, taking screenshots, and returning a verdict
(`REVIEW PASSED` / `REVIEW FAILED`) plus a summary. Only the screenshots the agent names in
its summary are reported, and a single **PDF report** (verdict + summary + those screenshots) is
built and attached to the ticket (on Jira, as an attachment; on GitHub, listed by name). The PDF
is rendered from HTML with headless LibreOffice (`soffice`), so set `review.report.enabled: false`
if LibreOffice is not available — the individual screenshots are still attached.

```yaml
# config/packages/ticket_pilot.yaml
ticket_pilot:
    review:
        enabled: true
        driver: agent
        url_pattern: 'https://{branch_slug}.review.example.com'   # or pass --url
        rules_file: '.ticket-pilot/review-context.md'             # trusted, project-specific guidance
        login: '%env(IA_REVIEW_LOGIN)%'
        password: '%env(IA_REVIEW_PASSWORD)%'
        # agent: 'cursor'                # empty = default_agent
        # merge_request_context: true    # inject the MR/PR description as "what was developed"
        # screenshot_dir: 'var/ticket-pilot/screenshots'
        # summary_start_marker: '<<<REVIEW_SUMMARY'
        # summary_end_marker: 'REVIEW_SUMMARY>>>'
        # report:
        #     enabled: true            # build a PDF report and attach it to the ticket
        #     soffice_binary: 'soffice'
        #     timeout: 120
```

```bash
php bin/console ia:review LYSI-2098 --branch=feature/LYSI-2098 --model=auto
```

> The browser itself is the agent's concern: configure a browser MCP for your agent (e.g.
> `.cursor/mcp.json` with `@playwright/mcp`). The bundle stays browser-agnostic and only
> orchestrates the agent. Everything project-specific lives in your `rules_file`, so the
> review prompt stays generic. The ticket/MR text is treated as untrusted and fenced against
> prompt injection.

[mcp]: https://github.com/microsoft/playwright-mcp

### `recipe` driver — replay a YAML recipe in headless Chromium

While implementing a ticket, the agent also writes a **test recipe** —
`.ticket-pilot/recipes/<key>.yaml`; `ia:review` replays it in **headless Chromium**
(chrome-php/chrome), runs the steps, takes screenshots, and reports the step results.

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
        driver: recipe   # default
        url_pattern: 'https://{branch_slug}.review.example.com'   # or pass --url
        # recipes_dir: '.ticket-pilot/recipes'
        # chrome_binary: '/usr/bin/chromium'   # empty = auto-detect
        # no_sandbox: true                     # required as root / in most Docker images
        # screenshot_dir: 'var/ticket-pilot/screenshots'
```

> Requires `composer require chrome-php/chrome` and a Chromium/Chrome binary in the runtime.
> Running as root or inside a container usually needs `no_sandbox: true` (Chrome's SUID
> sandbox is not configured there) — otherwise Chrome aborts at startup.
> The step logic is engine-agnostic (`RecipeRunnerInterface` / `BrowserPageInterface`), so you
> can plug in another browser engine.

## Ticket attachments

When enabled, the ticket's attachments are downloaded into a per-ticket folder before the
agent runs, and the prompt lists them so the agent reads them for context. Office documents
(`doc`/`docx`/`odt`/`rtf`) are converted to **PDF** (headless LibreOffice) so a PDF-capable
agent can read them; images and PDFs are kept as-is.

```yaml
# config/packages/ticket_pilot.yaml
ticket_pilot:
    attachments:
        enabled: true
        # dir: 'var/ticket-pilot/attachments'
        # convert_documents: true
        # soffice_binary: 'soffice'   # LibreOffice, for docx -> pdf
```

> Requires LibreOffice (`soffice`) in the runtime for document conversion. Currently
> implemented for the Jira source (`AttachmentDownloaderInterface`).

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
