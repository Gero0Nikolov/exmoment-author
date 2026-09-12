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

### `exmoau_post_generated`

`JobsExecutionController` emits this informational action once for each successfully created post:

```php
do_action(
    'exmoau_post_generated',
    $postId,
    $jobId,
    $context
);
```

Arguments:

- `$postId` is the positive ID of the final persisted WordPress post.
- `$jobId` is the positive originating `exmoau_job` ID.
- `$context` contains exactly `executionType`, `generationIndex`, `generationCount`, and `trigger`.

`executionType` is one of `single_instant`, `single_scheduled`, or `repeating_scheduled`. `trigger` is `manual`, `publish`, `schedule`, or an empty string when an unknown caller value was removed. Generation indices are positive and the count is never lower than the index.

The action fires inside the common per-generation success path after post insertion, the job/post back-reference, Yoast processing, and the featured-image attempt. At that point standard WordPress APIs can read the final persisted title and content. A missing featured image does not prevent the action because image generation failure does not make an otherwise valid article fail.

The action does not guarantee that any extension is installed, that a listener succeeds, that the post is suitable for a social network, or that a later scheduled run succeeds. It contains no article body, Author source pack, prompt, credential, or provider response. ExMoment Author has no dependency on ExMoment Social or Instagram; with zero listeners the action has zero additional side effects.

## Module-Level Hook Coverage

- Settings, Help, Log, and Library modules register admin page and AJAX hooks.
- Publication validation uses `wp_insert_post_data` filters.

## Related Docs

- [Modules Index](../modules/index.md)
- [Scheduler Module](../modules/scheduler.md)
- [Scheduler Lifecycle](../operations/scheduler-lifecycle.md)
