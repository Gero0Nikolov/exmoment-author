# Testing Strategy

ExMoment Author does not currently have a checked-in automated unit or browser test suite. Release validation therefore combines deterministic static checks, focused runtime probes, and authenticated browser coverage. Record environment-limited coverage instead of claiming an unexecuted provider workflow passed.

## Static baseline

Run before synchronizing a release:

```bash
git status --short
git diff --check
composer validate --no-check-publish
```

Lint every changed PHP file with `php -l`. For a broad architecture change, lint all first-party PHP files while excluding `vendor/`. Validate Markdown links with a repository-local path checker that ignores external URLs and anchors but fails on missing relative files.

Search changed text for trailing whitespace and active version drift. If a source Sass file changed, compile the established `resources/src/styles/admin/global.scss` entry point, confirm the corresponding file under `resources/dist/styles/admin/` changed, and inspect the diff for unrelated generated output. The repository currently has no `package.json` frontend test or lint script; do not invent one during release preparation.

## AI Client checks

Test text and image capabilities separately because discovery and support are model-dependent.

Minimum provider-neutral checks:

- WordPress AI Client unavailable;
- no compatible provider adapter installed;
- installed but unconfigured provider;
- explicit unavailable provider;
- explicit unavailable model;
- provider/model without the requested capability;
- automatic provider/model selection;
- debug-mode transport bypass;
- normalized invalid-request diagnostics;
- public messages do not contain provider secrets or raw payloads.

When a configured test provider is available, exercise successful text generation with both automatic and explicit selection. Exercise image generation separately and verify the returned `File` DTO is persisted as a media attachment. Provider catalog, quota, billing, and authentication checks are environment-dependent and should identify the provider and date without recording credentials.

## Job execution matrix

All job entry points should reach `JobsExecutionController::executeJob()` through the shared runner:

| Workflow | Entry point | Allowed job type |
| --- | --- | --- |
| Publish-triggered instant | `handleSavePost()` → `runJobNow()` | `single_instant` |
| Manual instant | `handleManualRun()` → `runJobNow()` | `single_instant` |
| Single scheduled | `runScheduledJob()` | `single_scheduled` |
| Repeating scheduled | `runScheduledJob()` | `repeating_scheduled` |

For each applicable path, verify global prompt inheritance, a valid job prompt override, mandatory protocol preservation, author context disabled, author context enabled with a valid display name, and safe fallback when no author context resolves. Validate article title/body parsing, hidden SEO metadata parsing, post author assignment, post status, Yoast metadata when integration is active, used-source persistence, and optional image handling.

Avoid running provider-backed jobs against production content packs during routine validation. Use dedicated disposable sources and restore all temporary settings, post meta, generated posts, media, and library fixtures.

## Admin browser checks

Use an authenticated local wp-admin session. For the job editor, verify:

- all three job-type controls retain their values and visibility rules;
- the custom system prompt preserves multiline text and a blank value restores inheritance;
- oversized prompt submission is rejected without overwriting the previous valid prompt;
- long Job Setup directory labels use a single-line ellipsis without horizontal overflow;
- short labels remain fully visible;
- the complete long label is present in the native `title` attribute;
- selected and unselected tiles retain equal geometry and existing padding;
- click and keyboard activation preserve `aria-pressed`, selection classes, and saved directory values;
- no new ExMoment Author console errors appear.

An unrelated third-party console error must be recorded separately and must not be presented as an ExMoment Author regression.

## Release-tree checks

After SVN synchronization, compare trunk and the new tag recursively. Check paths and byte contents, then compare relevant SVN properties such as `svn:executable`. Verify authoritative versions and changelog entries directly in both trees. Run the packaging/security scan against the distributable Git paths and both SVN release trees; review matches rather than deleting every occurrence automatically.

## Related documentation

- [Developer Setup](developer-setup.md)
- [WordPress.org Release Workflow](wordpress-org-release-workflow.md)
- [AI Request Lifecycle](../architecture/ai-request-lifecycle.md)
- [Jobs Admin Workflows](jobs-admin-workflows.md)

