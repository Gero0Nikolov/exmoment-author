# Hooks

This page summarizes notable hooks used by the plugin runtime.

## Core Hooks

- `init`:
  - module autoload at priority 3
  - scheduler event guard
- `admin_init`: scheduler event guard.
- `wp_loaded`: scheduler event guard.
- `admin_enqueue_scripts`: core admin assets.
- `login_enqueue_scripts`: login assets.
- `wp_ajax_exmoau_pulse_vibe`: pulse endpoint.
- `wp_ajax_nopriv_exmoau_pulse_vibe`: public pulse endpoint.
- `cron_request` filter: optional local Docker cron request adjustment.

## Jobs and Scheduler Hooks

- `exmoau_minutely_worker` custom cron hook.
- `cron_schedules` filter for custom interval registration.
- `save_post_exmoau_job` hooks for schedule sync, execution handling, and metadata.

## Module-Level Hook Coverage

- Settings, Help, Log, and Library modules register admin page and AJAX hooks.
- Publication validation uses `wp_insert_post_data` filters.

## Related Docs

- [Modules Index](../modules/index.md)
- [Scheduler Module](../modules/scheduler.md)
- [Scheduler Lifecycle](../operations/scheduler-lifecycle.md)
