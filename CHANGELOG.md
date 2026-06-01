# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  configured checks after the agent and before pushing; a failure raises
  `QualityGateFailedException` and aborts, so no branch is pushed and no merge request
  is opened for broken code.
