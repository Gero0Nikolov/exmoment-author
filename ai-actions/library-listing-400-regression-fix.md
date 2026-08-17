# Library Listing HTTP 400 Regression Fix

## Failure

Opening Author job `352` at `/wp-admin/post.php?post=352&action=edit` caused the initial Mixture panel to display `Unable to load the requested tab. Please try again.` The browser issued a `POST` to `/wp-admin/admin-ajax.php` with action `exmoau_get_mixture_tab`. The request included the valid setup nonce, `post_id=352`, `page=1`, `uniqueness=1`, `per_category=3`, `directive=post`, and these saved selections:

- `careers-chunk-01`
- `happiness-chunk-01`
- `hobbies-chunk-01`

The current local library contains only `motivation-chunk-01`. The endpoint returned HTTP `400` with:

```json
{"success":false,"data":{"message":"Invalid directory selection."}}
```

The silent Directive prefetch also sent the stale selections and received the same validation failure. Apache recorded both `POST /wp-admin/admin-ajax.php` responses as `400`. WordPress debug output contained no warning, fatal error, or exception.

## Root Cause

`JobsMetaController::renderSetupMetaBox()` copied stored directory names into the browser configuration without reconciling them against the current library. `JobSetupController::fetchTab()` then submitted every configured name. `JobsMetaController::prepareSetupAjaxRequest()` correctly rejected names outside the current server-side allowlist.

This was an older state-normalization defect exposed after the saved job directories and current local library diverged. Git blame traces both the unfiltered render state and strict request validation to initial commit `1591af9` from 2026-02-22. The Author worktree was clean before this fix, the browser loaded the current source asset with a timestamped version, and recent lifecycle-hook and AI-image work did not introduce the defect.

## Fix

The server-rendered setup state now intersects stored selections with the keys of the current validated library. Available saved selections remain selected; missing selections are omitted from the browser request without mutating post meta. Direct AJAX submissions containing stale, traversal, or otherwise unavailable directories still fail strict validation.

Changed files:

- `modules/jobs/JobsMetaController.php`
- `tests/runtime/library-listing-regression.php`
- `ai-actions/library-listing-400-regression-fix.md`

No JavaScript request contract, AJAX action, nonce, capability, referrer, path, pagination, or response schema changed.

## Verification

Browser verification on job `352` showed the Mixture panel loading `motivation-chunk-01`, selection toggling to `aria-pressed=true`, and the Directive tab loading its saved post type, status, author, and generation count. The matching setup AJAX requests returned HTTP `200`; no tab error or duplicate setup request appeared.

The focused runtime regression passed `13/13`. It proves that stale saved state is removed from the rendered request, available selections still validate, the Mixture handler preserves its response keys, stored post meta is not changed by rendering, and forged stale, traversal, invalid-nonce, and cross-host-referrer requests remain rejected. The existing article categorisation/job regression completed with `failure_count=0`. Both changed PHP files passed syntax checks; `git diff --check` passed. PHP emitted only the pre-existing nullable-parameter deprecation in `sanitizeDirectiveGenerationCount()`.

No provider-backed request, generation, commit, push, release, deployment, production access, ExMoment Social change, or secret logging was performed.
