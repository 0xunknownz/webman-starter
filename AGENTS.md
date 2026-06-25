# AGENTS.md

This file gives coding agents working in this repository the project rules and expected workflow.

## Command Rules

- Use `rg` or `rg --files` first when searching.

## Project Context

- This repository is a PHP library for Webman projects.
- Package namespace: `Z0x\WebmanStarter\`.
- Source root: `src/`.
- Public behavior includes API responses, trace IDs, error codes, and business exception handling.

## Coding Standards

- Use PHP 8.1+ compatible code.
- Start PHP files with `<?php` and `declare(strict_types=1);`.
- Prefer `final class` by default unless extension is intentionally part of the API.
- Use complete parameter and return type declarations.
- Prefer constructor property promotion and `readonly` properties for immutable dependencies or values.
- Keep directory names lowercase.
- Keep namespaces aligned with the PSR-4 path under `src/`.
- Avoid unrelated refactors while changing behavior.

## Webman Architecture Rules

- Keep domain and reusable support code independent from Webman framework classes when practical.
- Keep Webman `Request`, `Response`, and framework-specific behavior at controller, middleware, or handler boundaries.
- Use service classes for application workflows when behavior grows beyond simple helpers.
- Use repository contracts for persistence-facing service code instead of accessing models directly from services.
- Avoid circular service dependencies.
- Prefer dependency injection over static service lookups.

## Validation Before Finishing

- For changed PHP files, run syntax checks with `php -l <file>`.
- If tests or quality tools are added later, run the smallest relevant suite first, then broader checks when shared behavior changed.
- Before reporting completion, check the worktree with `git status --short`.

## Git Commit Convention

Use Conventional Commits.

Format:

```text
<type>(<scope>): <subject>
```

Rules:

- Write the subject in imperative mood, concise and specific.
- Keep the subject under 72 characters when practical.
- Use lowercase commit types.
- Do not end the subject with a period.
- Use a scope when it clarifies the touched area, for example `http`, `exception`, `docs`, `composer`, or `ci`.
- Separate body from subject with a blank line when a body is needed.
- In the body, explain why the change exists and any behavior or compatibility impact.
- Mark breaking changes with `BREAKING CHANGE:` in the footer.

Allowed types:

- `feat`: user-facing feature or new capability
- `fix`: bug fix
- `docs`: documentation-only change
- `style`: formatting-only change with no behavior impact
- `refactor`: code restructuring without behavior change
- `perf`: performance improvement
- `test`: test additions or test-only changes
- `build`: dependency, packaging, or build system change
- `ci`: CI workflow change
- `chore`: maintenance that does not affect runtime behavior
- `revert`: revert a previous commit

Examples:

```text
feat(http): add paginated response helper
fix(exception): preserve trace id in error responses
docs: document API response envelope
build(composer): require ramsey uuid
```

## Pull Request Notes

- Keep PRs focused on one behavior or maintenance goal.
- Include the verification commands that were run.
- Mention any intentionally skipped checks and why.
