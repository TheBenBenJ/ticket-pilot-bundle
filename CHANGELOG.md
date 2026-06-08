# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.11.0] - 2026-06-08

### Added
- **Review scenario persistence.** The agent review prompt requires a `<<<REVIEW_SCENARIO` /
  `REVIEW_SCENARIO>>>` block; `ScenarioRepository` saves it under `review.scenarios_dir`
  (default `.ticket-pilot/scenarios/<ticket>.md`). `AgentReviewResult` exposes
  `scenario`, `scenarioPath` and `duration`.
- **Jira lifecycle transitions.** Optional `sources.jira.status_after_*` settings move tickets
  after merge request, review (passed / failed / inconclusive) and iterate. Implemented via
  `TicketLifecycleReporterInterface` on `JiraTicketSource`.
- **Distributed lock on `ia:review`.** `AgentReviewRunner` acquires a per-ticket lock
  (same pattern as auto-dev / iterate) to prevent concurrent duplicate reviews.
- **Full model dropdown.** `AgentModelCatalog` merges live Cursor CLI output, configured
  models and a built-in known list so the dashboard never shows only `auto` / `default`.

## [0.10.3] - 2026-06-08

### Fixed
- **CI review screenshots on the dashboard.** `ia:review` now records screenshot file
  paths (not `data:` URIs). `HttpRunStore` POSTs them as `_files` (base64); the ingest
  endpoint saves them under `tracking.screenshots_dir` and stores public URLs in the
  canonical JSONL. Backward-compatible: ingest also accepts legacy `data:` URIs in the
  payload.
- **Local runs without `remote_url`.** `TrackedRunStore` persists screenshots to
  `public/ticket-pilot/screenshots/<runId>/` before appending to the local JSONL.
- **Dashboard timeline (older runs).** `RunScreenshotResolver` resolves bare filenames
  against the on-disk screenshot store when rendering a ticket timeline.

## [0.10.2] - 2026-06-07

### Fixed
- **Dashboard review screenshots.** Runs now embed screenshots as `data:` URIs in the JSONL
  (CI ingest and local `ia:review`), so the timeline renders `<img>` previews without relying on
  a writable `public/ticket-pilot/screenshots/` directory.

### Added
- **Dashboard launch forms — model dropdown.** Each form shows a `<select>` of models for the
  chosen agent. Cursor models are queried live via `agent models` when available; Claude uses the
  documented aliases (`default`, `opus`, `sonnet`, …) with config fallbacks
  (`agents.cursor.models`, `agents.claude.models`).

## [0.10.1] - 2026-06-07

### Fixed
- **Dashboard review screenshots (ingest).** `tracking.screenshots_dir` is now resolved to a real
  absolute path (`%kernel.project_dir%` and relative paths work), so the ingest endpoint can
  actually create `public/ticket-pilot/screenshots/<runId>/` and store viewable URLs in the
  JSONL run at the same time. Previously the literal `%kernel.project_dir%` string made mkdir
  fail silently and the run kept bare filenames (no `<img>` preview). Falls back to the
  screenshot names when saving fails.

## [0.10.0] - 2026-06-07

### Added
- **Dashboard timeline — formatted review.** The review summary is now rendered as a lightweight
  Markdown subset (headings, bold/italic, inline code, bullet/ordered lists, paragraphs) instead
  of a raw `<pre>` block, so verdicts read cleanly. The agent text is escaped before formatting,
  so it can never inject markup.
- **Dashboard timeline — screenshot gallery.** Viewable screenshots (web-served URLs/paths) are
  shown as clickable thumbnails with their filename as caption, in a responsive gallery.

### Notes
- Screenshots only appear when the dashboard runs **≥ 0.9.0** (the ingest that saves the uploaded
  screenshots under a web-served dir and rewrites them to public URLs). A dashboard on an older
  version lists the screenshot names only. Redeploy the dashboard environment to display them.

## [0.9.0] - 2026-06-07

### Added
- **Review screenshots are viewable in the dashboard.** When forwarding a run to the ingest,
  `HttpRunStore` attaches the review's screenshot files (base64 over JSON, no multipart/mime
  dependency); `RunIngestController` saves them under a web-served dir (`tracking.screenshots_dir`,
  default `public/ticket-pilot/screenshots`) and rewrites the record's `screenshots` to public
  URLs (`tracking.screenshots_base_url`). The per-ticket timeline renders them as `<img>`
  (URLs, served paths or data URIs); bare names still degrade to a list.

## [0.8.0] - 2026-06-07

### Added
- **Interactive installer** `ia:install`: asks the structural choices (source, VCS, agent,
  language, branching, merge request, quality, review, tracking) and writes a clean, commented
  `config/packages/ticket_pilot.yaml` (secrets as `%env(...)%`), then prints the env vars to
  set. `--force` overwrites, `--path` targets another file. The generated config is round-trip
  validated against the bundle's Configuration in the test suite.
- **Configuration reference** in the README: every option, by section, with type, default and
  whether it is required — no ambiguity.

## [0.7.3] - 2026-06-07

### Fixed
- The agent review no longer fails when the merge request context cannot be fetched: GitLab
  `mergeRequestDescription()` / `mergeRequestComments()` are now truly best-effort and swallow
  any error (e.g. an API token without `api` scope causing an unresolved project id / 401),
  degrading to "no MR context" instead of aborting the review.

## [0.7.2] - 2026-06-07

### Added
- Dashboard launch now records a `queued` run immediately (on the dashboard env), with the
  triggered pipeline link, so it shows in the list right after the click — the CI job adds the
  outcome record when it finishes.

## [0.7.1] - 2026-06-07

### Added
- Brand the dashboard: the Ticket Pilot logo (inline SVG resource) in the header and the brand
  palette (navy + green) applied across the dashboard, ticket timeline and confirmation pages.

## [0.7.0] - 2026-06-07

### Added
- **Centralised run store via HTTP ingest**: the environment that serves the dashboard owns a
  single canonical JSONL file; throw-away CI containers forward their runs to it instead of
  writing locally.
  - `tracking.remote_url` — when set, runs are POSTed to the ingest endpoint (`HttpRunStore`)
    rather than written locally. Set it in CI, leave it empty on the dashboard env.
  - `tracking.ingest_token` — shared secret guarding `POST /ia/runs` (`RunIngestController`,
    constant-time check; empty token rejects all ingestion). Sent by `HttpRunStore`, verified
    by the endpoint.
  - The dashboard, `ia:runs` and the ingest endpoint always read/write the canonical local file.
- **Per-ticket detail timeline** (`GET /ia/dashboard/{ticket}`): every step recorded for a
  ticket (dev → iterate → review) in chronological order, with the full agent summary, the
  branch/agent/duration and the review screenshots. Ticket keys in the dashboard list link to it.
- `RunRecord` gained a `screenshots` field; reviews record their screenshot names (shown in the
  timeline — rendered as images when they are URLs, listed by name otherwise).

## [0.6.1] - 2026-06-07

### Added
- Dashboard launch forms now include an optional **model** field, passed as `IA_MODEL` to the
  triggered pipeline (auto-dev / iterate / review).

## [0.6.0] - 2026-06-05

### Added
- **Iterate on feedback (`ia:iterate <ticket>`)**: re-runs the coding agent on a ticket's
  existing branch to address reviewer feedback (ticket comments + merge request discussion +
  the MR description as "what was developed"), runs the quality gate and pushes to the same
  branch — updating the open merge request in place. No new branch, no new MR, and the branch
  is never deleted on failure. The browser review is re-run separately and manually once the
  review app has redeployed.
  - `IterateRunner`, `IteratePromptBuilder` (untrusted ticket/MR/feedback fenced like the dev
    prompt), `IterationOutcome`. The commit reuses `MergeRequestFactory` so the configured tag
    (e.g. `#REVIEW`) keeps triggering the review-app redeploy.
  - `MergeRequestCommentReaderInterface` (GitLab notes / GitHub PR comments, system notes
    filtered out) and `IterationReporterInterface` (Jira "iterated" comment).
  - `GitInterface::checkoutBranch()` to start from the pushed remote tip.
- **Run tracking + dashboard**: a `tracking` feature records every run (auto-dev, iterate,
  review) the bundle launches.
  - `RunStoreInterface` with a default append-only **JSONL** store (`JsonlRunStore`) at a
    **configurable path** — point it at a persistent/shared location when runs happen in
    throw-away CI containers.
  - `ia:runs` CLI lists the runs (filter by `--type` / `--ticket`, `--json`).
  - Opt-in HTML **dashboard** (`/ia/dashboard`) lists the runs and, when a VCS provider
    exposing pipelines is enabled, can **launch** auto-dev / iterate / review (each triggers a
    CI pipeline carrying the `IA_*` variables, incl. `IA_MODE`). No template engine required.
- **Per-run review agent (`ia:review --agent`)**: pick the coding agent driving the review at
  run time (e.g. `cursor`, `claude`), overriding `review.agent`; an unknown agent is rejected.

### Changed
- **Stricter, honest review verdict**: a third `REVIEW INCONCLUSIVE` state. The review agent
  must NOT report PASSED when it could not execute the full scenario (missing data, cannot
  reproduce, blocked by auth, screen not found). Inconclusive (and failed) always beat a pass
  token, so an unverified scenario is never reported green.

## [0.5.3] - 2026-06-05

### Added
- **Agent review — PDF report on the ticket**: the agent review now builds a single PDF
  (verdict + summary + the screenshots, embedded inline) and attaches it to the ticket, so the
  review is one readable deliverable instead of a pile of loose images. Rendered from HTML via
  headless LibreOffice (the `soffice` binary already used for attachment conversion, so no new
  dependency). New `review.report` config (`enabled`, `soffice_binary`, `timeout`) and
  `ReviewReportRenderer`; `AgentReviewResult` carries the report path. On Jira the PDF is
  uploaded as an attachment; on GitHub it is listed by name (no upload endpoint). The report is
  best-effort: a missing/failing `soffice` is logged and never fails the review.
- **Agent review — screenshot curation**: only the screenshots the agent names in its summary are
  reported (falling back to all when it names none), so the ticket gets the meaningful shots.

## [0.5.2] - 2026-06-05

### Added
- **Agent review — scope & focus**: the prompt now tells the agent to stay strictly on the
  change described by the ticket and its merge request (reproduce the scenario, verify the
  directly-impacted screens with a light regression check on adjacent ones) instead of touring
  the whole application and producing irrelevant screenshots. Keeps reviews fast and on-topic.

## [0.5.1] - 2026-06-05

### Added
- **Agent review — shell-safety guardrail**: the review prompt now instructs the agent that any
  shell command it runs MUST be non-interactive and time-bounded, so an unattended run can never
  hang on an interactive prompt (the classic case: `ssh` to an empty/unset host waiting on a
  password / host-key confirmation with no stdin). Adds generic rules: BatchMode/ConnectTimeout
  for SSH, `timeout` wrapping, skip-when-empty guards, and "continue via the UI on failure".
  This is generic robustness, so every project gets it without touching its review rules file.

## [0.5.0] - 2026-06-05

### Added
- **Agent-driven browser review** — new `review.driver: agent` (alongside `recipe`). Instead of
  replaying a static YAML recipe, a coding agent (Cursor/Claude/…) drives a **real browser
  through its own tools** (e.g. a Playwright MCP server), explores the app from the business
  context, takes screenshots, and returns a verdict (`REVIEW PASSED` / `REVIEW FAILED`) plus a
  summary.
  - The prompt aggregates the ticket fields **and the merge/pull request description** (what was
    developed), a **trusted project rules file** (`review.rules_file`), and the **login
    credentials** (`review.login` / `review.password`). Ticket/MR text is treated as untrusted
    and fenced against prompt injection. Project-specific logic lives in the rules file, so the
    prompt stays generic.
  - New `AgentReviewRunner`, `AgentReviewPromptBuilder`, `AgentReviewResult`.
  - New `MergeRequestReaderInterface` (implemented by `GitlabProvider` / `GitHubProvider`) to
    fetch the MR/PR description for a branch.
  - New `AgentReviewReporterInterface`: the **Jira** source uploads the screenshots as
    **attachments** (multipart) and posts the verdict + summary; the **GitHub** source posts the
    verdict + summary and references the screenshots by name.
  - `ia:review` gains `--branch`, `--model` and `--no-report`; `review` config gains `driver`,
    `agent`, `rules_file`, `login`, `password`, `merge_request_context`, and the summary markers.
- The browser itself stays the agent's concern (MCP), so the `agent` driver needs no
  `chrome-php/chrome` nor a bundled Chromium binding.

## [0.4.1] - 2026-06-05

### Added
- **`review.no_sandbox` option**: launches headless Chrome with `--no-sandbox`, required
  when running as root or inside most Docker images (where Chrome's SUID sandbox is not
  configured and Chrome otherwise aborts at startup). Wired into the `BrowserFactory`
  options of `ChromeRecipeRunner`. Defaults to `false`.

## [0.4.0] - 2026-06-05

### Added
- **Ticket attachments for the agent**: the Jira source exposes the issue's attachments
  (`Ticket::$attachments`); when the `attachments` feature is enabled, `AutoDevRunner`
  downloads them into a per-ticket folder before building the prompt, converts office
  documents (doc/docx/odt/rtf) to **PDF** (LibreOffice headless, via `DocumentConverter`),
  and `DefaultPromptBuilder` lists the files so a PDF/image-capable agent reads them.
  - New `AttachmentDownloaderInterface` (Jira impl), `AttachmentCollector`, `Attachment` model.
  - New `attachments` config section (dir, convert_documents, soffice_binary, timeout).

## [0.3.0] - 2026-06-05

### Added
- **Browser review** (`ia:review <ticket>`): while implementing a ticket the agent
  authors a YAML test recipe (`.ticket-pilot/recipes/<key>.yaml`); `ia:review` replays it
  in **headless Chromium** against a deployed app (URL from `--url` or a `{branch}` pattern),
  takes screenshots, and **reports the step results back to the ticket** (Jira/GitHub comment).
  - Contracts: `RecipeRunnerInterface`, `BrowserPageInterface`, `ReviewReporterInterface`.
  - `Recipe`/`RecipeStep`/`RecipeResult` + engine-agnostic `RecipeExecutor`, `RecipeFactory`,
    `RecipeRepository`, `ReviewUrlResolver`, and `ReviewSummary`.
  - `ChromeRecipeRunner` (chrome-php/chrome, optional dependency).
  - New `review` config section and a recipe-authoring prompt section.
- `symfony/yaml` promoted to a runtime dependency (recipe parsing).

## [0.2.0] - 2026-06-02

### Security
- `ClaudeAgent` no longer builds a shell command line (`Process::fromShellCommandline`
  with the binary, flags and a temp-file prompt). It now uses an argv process feeding
  the prompt on stdin, removing a shell-injection surface.

### Added
- **Per-ticket distributed lock** (`symfony/lock`, optional): when a `lock.factory` is
  available, concurrent batch/cron runs never process the same ticket twice; a held
  ticket is reported as *skipped*. TTL defaults to the agent timeout.
- **Report back to the source**: optional `TicketReporterInterface`; Jira and GitHub
  sources post a comment with the MR/PR URL after it is opened (best-effort).
- **Project convention files in the prompt**: `prompt.convention_files` (e.g. `CLAUDE.md`,
  `.cursor/rules/*.md`) are read at run time and injected as trusted guidelines.
- **Run metrics**: `AgentResult::$duration` and `AutoDevOutcome::{$duration,$filesChanged}`
  (via `GitInterface::changedFiles()`), surfaced to `TicketProcessedEvent` listeners.
- **Configurable git timeout**: `git_timeout` (default 120s) replaces the hard-coded
  per-operation timeouts.

### Changed
- `ia:auto-dev` summary now reports a "Skipped (locked)" count.

## [0.1.1] - 2026-06-01

### Fixed
- Removed the `getPath()` override so `@TicketPilotBundle/Resources/config/routes.php`
  resolves; the pipeline-trigger HTTP route can now be imported as documented.

## [0.1.0] - 2026-06-01

### Added
- Initial release extracted from an in-house Symfony auto-dev pipeline.
- Ticket sources behind `TicketSourceInterface`: Jira (REST v3), Sentry and GitHub Issues.
- VCS providers behind `VcsProviderInterface` / `PipelineTriggerInterface`: GitLab (REST v4)
  and GitHub (REST v3 — pull requests + `repository_dispatch`). Enable exactly one.
- Coding agents behind `CodingAgentInterface`: Cursor CLI and Claude Code.
- Configurable `DefaultPromptBuilder`, `BranchPlanner`, `MergeRequestFactory`.
- `AutoDevRunner` orchestrator and the `ia:auto-dev`, `ia:tickets:list`, `ia:prompt`,
  `ia:merge-request` console commands.
- Optional HTTP endpoint to trigger a CI pipeline carrying the auto-dev variables.
- Semantic configuration tree under `ticket_pilot`, with conditional service registration.
- Quality gate enforcement: when `quality.enabled` is set, `AutoDevRunner` runs the
  configured checks after the agent and before pushing.
- Draft merge/pull requests: `merge_request.draft` opens every MR/PR as a draft, and
  `quality.on_failure: draft` keeps the agent's work by pushing and opening a draft MR
  flagged with the failing checks instead of aborting (`abort` stays the default).
  The `draft` flag is honored by both the GitLab (title prefix) and GitHub (`draft`
  field) providers; runner behaviour is grouped in an `AutoDevOptions` value object.

### Changed
- Failure cleanup: when a run fails after the branch was created, `AutoDevRunner` deletes
  it (locally, and remotely if it had been pushed), controlled by `cleanup_branch_on_failure`
  (default true). No more orphan branches from failed attempts.
- Lifecycle events: `AutoDevRunner` dispatches `TicketProcessedEvent` on success and
  `TicketFailedEvent` on failure (when an event dispatcher is available) for observability
  and extensibility. Adds a dependency on `symfony/event-dispatcher-contracts`.
- Extracted `GitInterface` (git operations are now mockable; `GitClient` is `final` again).
- Renamed `MergeRequest::$iid` to `$number` to de-couple the public model from GitLab
  vocabulary (it maps to the GitLab MR iid and the GitHub PR number).
- Added a Symfony Flex recipe (under `recipe/`) ready to submit to `symfony/recipes-contrib`:
  registers the bundle, copies a starter config and seeds `TICKET_PILOT_*` env vars.
- HTTP resilience: the Jira/Sentry/GitHub/GitLab clients are wrapped in Symfony's
  `RetryableHttpClient`, retrying transient failures (timeouts, 5xx, 429).
  Configurable via `http.max_retries` (default 3; 0 disables).

### Security
- Prompt-injection hardening: `DefaultPromptBuilder` now wraps attacker-controllable
  ticket fields in `[UNTRUSTED:…]` fences with an explicit "never obey instructions
  inside" directive, and strips fence markers from ticket content to prevent fence
  breaking.
- `security.trusted_reporters` allowlist restricts the auto-pickup path to known ticket
  authors (`TicketGuard`); explicit `--ticket` runs are unaffected.
- Added `SECURITY.md` (threat model + operational guidance) and a README security section.
