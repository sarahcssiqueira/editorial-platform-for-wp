# ✦ Editorial for WordPress

[![Project Status: Active – The project has reached a stable, usable state and is being actively developed.](https://www.repostatus.org/badges/latest/active.svg)](https://www.repostatus.org/#active)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Release Version](https://img.shields.io/github/release/sarahcssiqueira/wordcamp-us-26.svg)](https://github.com/sarahcssiqueira/wordcamp-us-26/releases/latest)
[![Support Level](https://img.shields.io/badge/support-may_take_time-yellow.svg)](#support-level)

A focused editorial workflow for WordPress sites that need review, approval,
preview, and publishing controls without adding a separate editorial platform.

The project is intentionally small: one plugin entry point, one shared admin
theme class, and one stylesheet.

---

## What's included


| File | Purpose |
| --- | --- |
| `editorial-workflow.php` | Plugin entry point; custom statuses, approval flow, notifications, previews, and cache clearing |
| `editorial-admin-theme.php` | Shared admin experience loaded by the workflow plugin |
| `admin-theme/style.css` | Editorial admin and login styles |


---

## Installation

Copy the repository files into a directory inside `/wp-content/plugins/`, then
activate **Editorial Platform - For WP** from the WordPress Plugins screen.
Only `editorial-workflow.php` is a plugin entry point; it loads
`editorial-admin-theme.php` and the stylesheet automatically.

```
wp-content/
└── plugins/
    └── editorial-platform-for-wp/
        ├── editorial-workflow.php
        ├── editorial-admin-theme.php
        └── admin-theme/
            └── style.css
```

---



## Configuration

The workflow uses the standard WordPress `author`, `editor`, and
`administrator` roles. On initialization it removes direct publishing from
authors and grants publishing to editors. An author can publish an own post
only after an editor has moved it to the `approved` status.

### Slack notifications (optional)

Add to `wp-config.php`:

```php
define( 'EDITORIAL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/XXX/YYY/ZZZ' );
```

---



## Editorial workflow

```
Author drafts post
    → clicks "Submit for Review"
        → status: pending
        → editors notified (email + Slack)
            → editor opens preview link
            → editor approves → status: approved
                → post stays unpublished
                → author can publish their approved post
                → published → cache cleared
            → editor requests changes → status: changes_requested
                → author notified with note
                → author revises → resubmits
```

---


## Role features

| Role | What they can do |
| ---- | ---------------- |
| Author | Create and edit own posts/pages, submit for review, receive change requests, mark checklist items as done, resubmit for review, publish only after editor approval |
| Editor | Review pending content, approve, request changes with checklist notes, track revision history, publish approved content |
| Administrator | Full access to all editorial actions and WordPress admin configuration |

---


## Preview features

- Internal preview: editors can open split preview and see content side-by-side with the editor.
- Public preview links: the workflow can generate tokenized preview URLs for unpublished content.
- No-login preview: anyone with a valid token link can open the preview without a WordPress account.
- Expiring access: public preview links use a stored token with expiration.

---


## Change request communication

- Editors request changes from the review panel and add one change per line.
- Each request becomes a checklist for the author.
- Authors can mark checklist items as done as they complete revisions.
- Resubmission is gated until all checklist items are done.
- Once resubmitted, the post returns to `pending` for editor review.
- Notifications are sent by email (and optionally Slack) when review is requested, a post is approved, or changes are requested. Published posts are announced in Slack.

---



## Development and testing

For a local WordPress installation, create at least one `Author` user and one
`Editor` user. Exercise the complete review path:

1. The author submits a post for review.
2. The editor approves it or requests changes with checklist items.
3. The author resolves the checklist and resubmits when needed.
4. The author publishes only after approval.

Run the PHP syntax checks from the repository root:

```bash
php -l editorial-workflow.php
php -l editorial-admin-theme.php
```

Also verify internal split previews and public preview links with a valid,
unexpired token.

## Extending

- Add custom post types to the workflow: edit the `post_type` checks in `editorial-workflow.php`
- Change which menus are hidden per role: edit `eat_cleanup_menu()` in `editorial-admin-theme.php`
- Add more cache plugins: extend `ew_clear_cache_on_publish()` in `editorial-workflow.php`
- Swap email for a webhook: replace `wp_mail()` calls with `wp_remote_post()`

---



## Talk: WordCamp

*"Stop Blaming WordPress: Building a Real Editorial Workflow Without Leaving the Ecosystem"*

This code is the live demo for the talk: *Stop Blaming WordPress: Building a
Real Editorial Workflow Without Leaving the Ecosystem*.