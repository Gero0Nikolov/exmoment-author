# Version 1.3.5 SVN Release Report

## Final status

**PASS** — ExMoment Author `1.3.5` was published to WordPress.org SVN at revision `3639646`. The committed remote trunk and tag are identical, the working copy is clean, prior tags remain unchanged, and WordPress.org has propagated version `1.3.5`, its changelog, and the public release ZIP.

## Release version and scope

- Release: `1.3.5`
- Scope: canonical Yoast SEO title templates for generated article titles.
- Git source commit: `1292c8d` (`yoast seo title update;`).
- Additional Git commit: intentionally not created by this release workflow.
- WordPress.org package policy: runtime plugin files, compiled assets, screenshots, production Composer dependencies, and release-safe canonical documentation only. Tests, local tooling, archive material, and operational release reports remain excluded.

The canonical generated-title path included in this release is:

```text
AI article-specific SEO_TITLE
→ normalization and validation
→ exact Yoast-variable deduplication
→ append %%sep%% %%sitename%%
→ store in _yoast_wpseo_title
→ Yoast resolves the configured separator and current site name
```

## Yoast SEO title changes

- Generated titles now store the canonical `%%sep%% %%sitename%%` Yoast suffix instead of a fully rendered site suffix.
- Exact existing `%%sep%%` and `%%sitename%%` variables are removed before one canonical suffix is appended.
- Literal punctuation and possible site-name text are preserved because they may be legitimate article-title content.
- Invalid or empty titles retain the existing no-write behavior.
- Missing or inactive Yoast remains a safe no-op.

## Version files and changelog

The authoritative production locations report `1.3.5`:

- plugin header in `exmoment-author.php`;
- production `resourceVersion` in `Core.php`;
- `Stable tag` in `readme.txt`.

The `1.3.5` changelog is limited to the Yoast title-template correction. Category-selection and ancestor-assignment behavior remains documented under the already-published `1.3.4` entry.

## Git source validation

- Yoast title-template regression: 24/24 assertions passed.
- Categorisation regression: 47/47 assertions passed; fixtures self-cleaned.
- Featured-image regression: 8/8 assertions passed.
- First-party Git PHP lint: 36/36 files passed.
- Composer metadata: valid.
- Git documentation links: 52 Markdown files checked with no missing local targets.
- Whitespace and diff validation: passed.

PHP 8.4 emitted the pre-existing nullable-parameter deprecation for `JobsMetaController::sanitizeDirectiveGenerationCount()` during lint. It is not a syntax failure and is outside the `1.3.5` release scope.

## SVN synchronization and package validation

Seven reviewed distributable files were synchronized byte-for-byte from Git into trunk:

- `Core.php`
- `exmoment-author.php`
- `readme.txt`
- `modules/seo/YoastSeoIntegration.php`
- `docs/architecture/prompt-and-author-context.md`
- `docs/modules/jobs-module.md`
- `docs/modules/seo-module.md`

Validation results:

- Added SVN paths: one history-preserving `tags/1.3.5` copied tree; no standalone distributable additions.
- Modified SVN trunk files: seven.
- Deleted SVN paths: zero.
- `tags/1.3.5` copy history: copied from trunk at working-copy revision `3639642`.
- Trunk/tag path, byte, and property parity: passed.
- Trunk and tag packaged files: 127/127.
- Trunk and tag first-party PHP lint: 33/33 files passed in each tree.
- Trunk and tag Composer metadata: valid.
- Trunk and tag documentation links: 49 Markdown files checked in each tree with no missing local targets.
- Packaging contamination audit: no prohibited development paths, workstation references, strong secret signatures, project test suite, Docker configuration, database/log files, archive material, or operational release reports.
- Pending SVN diff trailing-whitespace validation: passed.
- Existing `tags/1.3.3` and `tags/1.3.4` immutability checks: passed.

The Git-only preparation report, this publication report, and their reference-index links were intentionally excluded from WordPress.org SVN because they record operational release details rather than distributable product documentation.

## Publication and post-commit verification

- SVN commit message: `Release version 1.3.5`
- SVN revision: `3639646`.
- Remote trunk last-changed revision: `3639646`.
- Remote `tags/1.3.5` last-changed revision: `3639646`.
- Remote trunk/tag recursive diff: empty.
- Remote trunk/tag packaged files: 127/127.
- Post-commit working-copy status: clean, apart from known ignored `.DS_Store` files.
- Prior `tags/1.3.4`: unchanged; last-changed revision remains `3639544`.
- Prior `tags/1.3.3`: unchanged; last-changed revision remains `3639259`.
- WordPress.org plugin API propagation: complete; reported version `1.3.5`, the `1.3.5` changelog, and `exmoment-author.1.3.5.zip`.
- Public WordPress.org ZIP validation: 127 files, plugin header and stable tag `1.3.5`, and zero byte mismatches against committed `tags/1.3.5`.

