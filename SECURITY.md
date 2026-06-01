# Security model

This bundle runs an **autonomous coding agent** that edits your repository, commits,
pushes a branch and opens a merge/pull request — typically inside CI, with a token that
can write to the repository. That is powerful, and it carries real risk. Read this
before pointing it at any repository that contains secrets.

## Threat: ticket-driven prompt injection

The agent's prompt is built from ticket fields (title, description, comments). On most
trackers **anyone** can open an issue, comment, or add the trigger label. A malicious
ticket can therefore try to instruct the agent to read `.env`, print or exfiltrate
secrets, change CI/git config, install packages, or run unrelated commands — classic
**prompt injection**.

There is no way to make an LLM agent perfectly injection-proof. This bundle reduces the
risk with defence in depth; **you** must add the rest with environment isolation.

### What the bundle does

- **Untrusted-data fencing.** `DefaultPromptBuilder` wraps every ticket-derived field in
  `[UNTRUSTED:field] … [/UNTRUSTED:field]` fences and instructs the agent, up front, to
  treat their content strictly as a task description and never as instructions. Fence
  markers occurring inside ticket content are stripped so the fence cannot be closed early.
- **Trusted-reporters allowlist.** `security.trusted_reporters` restricts the *auto-pickup*
  path (`ia:auto-dev` without `--ticket`) to tickets authored by known reporters. An
  explicit `--ticket` run is treated as a deliberate human action.
- **No-broken-code gate.** With `quality.enabled`, checks run before any push; failures
  abort or open a clearly-flagged **draft** (`quality.on_failure`).
- **Draft MRs.** `merge_request.draft` keeps every result a proposal a human must review.
- **Commit exclude-paths.** `commit.exclude_paths` un-stages infra/secret files so the
  agent's commit cannot include them (a safety net, not a sandbox).

### What you must do

- **Run in a disposable, isolated environment** (ephemeral CI job, container, or throwaway
  clone). Treat any run as capable of executing arbitrary code.
- **Scope the token to the minimum**: a single repository, write to code + open MRs only.
  Prefer short-lived / fine-grained tokens. Never use a personal admin token.
- **Keep real secrets out of the agent's environment.** Inject only what the build needs.
- **Require human review.** Keep MRs as drafts; never auto-merge agent output.
- **Restrict the HTTP trigger.** The optional `/ia/auto-dev` endpoint starts a pipeline —
  put it behind your firewall/authentication. It is opt-in and not imported by default.
- **Pin the agent CLI version** and review the agent's own permission flags
  (`agents.claude.skip_permissions` runs Claude Code without per-action prompts; only
  enable it in an isolated runner).

## Reporting a vulnerability

Please open a private report via GitHub Security Advisories on the repository rather than
a public issue.
