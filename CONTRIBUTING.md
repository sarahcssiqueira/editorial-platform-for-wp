# Contributing Guidelines

Thanks for contributing to this project.

## Scope

This repository contains two WordPress plugins and one shared admin theme stylesheet:

- `editorial-workflow.php`
- `editorial-admin-theme.php`
- `admin-theme/style.css`

Please keep changes focused, minimal, and consistent with existing behavior.

## Local setup

1. Copy these files into `wp-content/plugins/` of a local WordPress install.
2. Ensure both plugins are loaded.
3. Use at least two test users:
   - `Author` (writer flow)
   - `Editor` (review flow)

## Required checks

Run syntax checks before opening a PR:

```bash
php -l editorial-workflow.php
php -l editorial-admin-theme.php
```

If your change affects CSS-only behavior, still run the PHP checks to avoid regressions.

## Workflow-specific expectations

When changing editorial logic, test these paths:

1. Author submits for review (`pending`).
2. Editor requests changes (`changes_requested`) with checklist items.
3. Author marks checklist items done.
4. Author resubmits for review (returns to `pending`).
5. Editor approves (`approved`).
6. Author publishes approved content.

Also verify preview behavior:

- Internal split preview works in editor screens.
- Public preview links open without login when token is valid.

## Code style

- Follow existing style in each file.
- Prefer small, targeted edits over broad rewrites.
- Avoid unrelated refactors in the same PR.
- Keep user-facing copy clear and role-accurate (`Author`, `Editor`).

## Commit messages

Use clear conventional-style messages when possible:

- `feat(editorial-workflow): ...`
- `fix(editorial-admin): ...`
- `style(editorial-admin): ...`
- `docs: ...`

## Pull requests

Please include:

1. What changed.
2. Why it changed.
3. How you tested it (include role-based flows).
4. Screenshots or short recordings for UI updates.

## Security and privacy

- Do not commit secrets, tokens, or webhook URLs.
- Treat preview links as sensitive while active.
- Keep access checks strict for editorial actions.
