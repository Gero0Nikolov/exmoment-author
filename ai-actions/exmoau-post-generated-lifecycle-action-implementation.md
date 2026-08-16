# `exmoau_post_generated` Lifecycle Action Implementation

## Status

PASS — the local ExMoment Author code now emits one generic WordPress action for each successfully persisted generated post. No Social or Instagram class, setting, or conditional was added to Author.

## Contract

```php
do_action('exmoau_post_generated', $postId, $jobId, $context);
```

The action is emitted by `JobsExecutionController::executeJob()` after post creation, the Author job back-reference, Yoast processing, and the featured-image attempt. Instant, single-scheduled, repeating-scheduled, and multi-generation executions all converge on this boundary.

The context contains only:

- `executionType`;
- `generationIndex`;
- `generationCount`;
- `trigger`.

It never contains article content, source files, prompts, credentials, or provider responses. Listener absence has no effect. Optional listeners own their own failure isolation.

## Verification

- Focused runtime regression: 25 passed, 0 failed.
- Live local proof: repeating job `355` created published post `531`; the action was consumed by an optional ExMoment Social listener after the JPEG featured-image assignment.
- The Author plugin header remains `1.3.6`. The recommended next development/release version for this new extension contract is `1.3.7`; no release preparation, SVN change, commit, or push was performed.

Canonical documentation: `docs/architecture/hooks.md` and `docs/modules/jobs-module.md`.
