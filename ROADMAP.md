# Roadmap

Tracking of the analysis-driven improvements. Done items shipped in the version noted.

## Shipped

### v0.3.0
- 🔥 **Browser review** (`ia:review`) — the agent writes a YAML test recipe while
  implementing the ticket; the command replays it in headless Chromium, screenshots,
  and reports the step results back to the ticket (Jira/GitHub comment).

### v0.2.0
- 🔴 **Shell-injection fix** in `ClaudeAgent` (argv + stdin, no shell).
- 🔴 **Per-ticket distributed lock** (`symfony/lock`) for concurrent batch/cron runs.
- 🟠 **Configurable git timeout** (`git_timeout`).
- 🟠 **Run metrics** — agent duration + changed files on `AgentResult` / `AutoDevOutcome`.
- 🔥 **Report back to the source** — `TicketReporterInterface`, Jira/GitHub post the MR URL.
- 🔥 **Convention files in the prompt** — `prompt.convention_files` (CLAUDE.md, .cursor/rules/*.md).

### v0.1.x
- Interface-based core, Jira/Sentry/GitHub sources, GitLab/GitHub providers, Cursor/Claude
  agents, quality gate, draft MRs, prompt-injection fencing, trusted-reporters allowlist,
  branch cleanup on failure, lifecycle events, HTTP retries, Flex recipe (unpublished).

## Planned

### Fixes / hardening
- **Attach review screenshots to the ticket** — currently `ia:review` posts the results and
  the screenshot file names; upload the images as Jira attachments (multipart) / embed them
  in the GitHub comment.
- **Adapt the prompt to the ticket type** — give the agent stronger "fix the root cause,
  don't mask the error" guidance for bugs/Sentry issues (use `Ticket::isBug()` / source).
- **`GitClient` integration tests** — exercise a real local `git` repo (create branch,
  commit, changed files) in a temp dir.
- **Publish the Flex recipe** to `symfony/recipes-contrib` (the `recipe/` dir is ready).
- **`env` support in the quality gate** — run `APP_ENV=test make test-php` without the
  `sh -c` workaround (`quality.commands` entries with an env map).

### Features (by priority)
1. **Iteration on a rejected MR** — `ia:iterate --mr=<id>`: fetch review comments, inject
   them into the prompt, re-run the agent on the same branch.
2. **Linear source** — implement `LinearTicketSource` (GraphQL); `TicketSourceInterface`
   and `TicketReporterInterface` are ready.
3. **Replay** — `ia:merge-request --branch=<name>` to open an MR for an existing branch
   when the push succeeded but the MR step failed.
4. **`--estimate`** — ask the agent to rate complexity (1/3/5/8) without touching the repo.
5. **Native Slack/Teams notifier** — a configurable `slack_webhook` listener on
   `TicketProcessedEvent` / `TicketFailedEvent` (saves every team rewriting it).
6. **`--watch` mode** — long-running `ia:auto-dev --watch --interval=300` as a cron-free
   alternative.
7. **`--model` per source** — cheap model for Sentry, premium for Jira features.
8. **YAML file source** — feed the pipeline from a local file (batch migrations).
9. **Web dashboard** `/ia/` — last runs (ticket, MR, status, duration) from JSON in
   `var/ticket-pilot/`.

Contributions welcome — open an issue or PR.
