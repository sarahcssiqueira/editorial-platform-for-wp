# ✦ Editorial for WordPress

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



## Roles


| Role            | Can do                                    |
| --------------- | ----------------------------------------- |
| Author          | Write, edit own posts, submit for review, publish own approved posts |
| Editor          | Review, approve, request changes, publish approved posts |
| Administrator   | Everything                                |


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