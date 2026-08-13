# ✦ Editorial for WordPress

[![Project Status: Active – The project has reached a stable, usable state and is being actively developed.](https://www.repostatus.org/badges/latest/active.svg)](https://www.repostatus.org/#active)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Release Version](https://img.shields.io/github/release/sarahcssiqueira/wordcamp-us-26.svg)](https://github.com/sarahcssiqueira/wordcamp-us-26/releases/latest)
[![Support Level](https://img.shields.io/badge/support-may_take_time-yellow.svg)](#support-level)

A focused editorial product built on top of WordPress core.
No heavy plugins. No headless layers. Just two plugins and a CSS file.

---

## What's included


| File                                         | Purpose                                                       |
| -------------------------------------------- | ------------------------------------------------------------- |
| `/editorial-workflow.php`          | Custom statuses, approval flow, notifications, cache clearing |
| `/editorial-admin-theme.php`       | Role-aware menus, admin bar, dashboard widget, login styles   |
| `/editorial-admin-theme/style.css` | Dark editorial admin theme                                    |


---

## Installation

Drop the contents of `plugins/` into your `/wp-content/plugins/` folder.
Files in `plugins/` are loaded automatically — no plugin activation needed.

```
wp-content/
└── plugins/
    ├── editorial-workflow.php
    ├── editorial-admin-theme.php
    └── editorial-admin-theme/
        └── style.css
```

---



## Configuration



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
                → author can now publish
                → published + cache cleared
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



## Extending

- Add custom post types to the workflow: edit the `post_type` checks in `editorial-workflow.php`
- Change which menus are hidden per role: edit `eat_cleanup_menu()` in `editorial-admin-theme.php`
- Add more cache plugins: extend `ew_clear_cache_on_publish()` in `editorial-workflow.php`
- Swap email for a webhook: replace `wp_mail()` calls with `wp_remote_post()`

---



## Talk: WordCamp

*"Stop Blaming WordPress: Building a Real Editorial Workflow Without Leaving the Ecosystem"*

This code is the live demo for the talk.
~150 lines of PHP + ~300 lines of CSS = a purpose-built editorial platform.