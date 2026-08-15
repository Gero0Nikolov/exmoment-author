# Version 1.3.6 SVN Release Report

## Final status

**PASS** — ExMoment Author `1.3.6` was published to WordPress.org SVN at revision `3643837`. The committed remote trunk and immutable `1.3.6` tag are byte- and property-identical, the working copy is clean apart from known ignored `.DS_Store` files, prior tag `1.3.5` remains unchanged, and WordPress.org has propagated the version, changelog, and public release ZIP.

## Release version and scope

- Release: `1.3.6`
- Scope: mandatory article-title prompt hardening.
- Git source: the intentionally uncommitted working tree based on `d367c9b`.
- Additional Git commit: intentionally not created by this release workflow.
- WordPress.org package policy: runtime plugin files, compiled assets, screenshots, production Composer dependencies, and release-safe canonical documentation only. Tests, local tooling, archive material, and operational release reports remain excluded.

## Root cause and implementation

The mandatory article response protocol already required exactly one leading Markdown or HTML top-level title, but it did not define the editorial quality or provenance of that title strongly enough. Models could therefore return a section heading, opening text, or a heading joined to the following paragraph as the title while technically attempting to satisfy the response shape.

`JobsExecutionController::CLOSING_SYSTEM_MESSAGE` now requires a natural, compelling, standalone, article-specific editorial title that accurately represents the complete article. It prohibits generic or templated titles, copying a section heading, excerpt, opening paragraph, or opening sentence, and joining an `<h2>` or other heading to body text.

The protocol also states its precedence explicitly: mandatory ExMoment Author output requirements always apply. Global or per-job editorial instructions may refine tone, style, audience, subject matter, and editorial direction, but cannot override the mandatory response contract.

## Prompt composition and parser boundary

The production request order remains:

```text
mandatory ExMoment Author response protocol
→ mandatory WordPress category-selection contract
→ effective global or per-job editorial instructions
→ optional generated author context
→ separate source/content messages
```

A per-job prompt still replaces only the global editorial section. It supplements rather than removes the plugin-level response requirements.

Correctly structured leading `#` or `<h1>` titles continue through `extractTitleAndBody()` and the existing `createPost()` sanitization into WordPress `post_title`. The legacy content-derived fallback remains unchanged because the investigation found no corruption of correctly structured titles. The normal editorial title also remains separate from the hidden `SEO_TITLE` field and Yoast template composition.

## Files changed in Git

- `Core.php`
- `exmoment-author.php`
- `readme.txt`
- `modules/jobs/JobsExecutionController.php`
- `tests/runtime/article-categorisation-regression.php`
- `docs/architecture/prompt-and-author-context.md`
- `docs/modules/jobs-module.md`
- `docs/references/index.md`
- `docs/references/version-1.3.6-svn-release-report.md`

## Regression and validation coverage

The article categorisation runtime regression now also validates global prompt inheritance, real per-job custom prompt resolution, author context on and off, mandatory-before-custom ordering, mandatory title rules in every composition, structured title parsing, and final `post_title` persistence.

The release validation also covers the existing Yoast SEO title-template regression, featured-image prompt regression, all first-party PHP syntax, Composer metadata, documentation links, whitespace, packaging contamination, SVN trunk/tag parity, and conflict-marker checks.

Three provider-backed validation attempts used synthetic source material with global, custom, and author-context variants. All were blocked before output by the existing WordPress AI Client HTTP 400 `prompt_invalid_argument` error. No provider-backed title-quality pass is claimed, and the transport issue remains outside the focused `1.3.6` scope.

## Git validation

- Article categorisation and prompt-composition regression: 63/63 assertions passed; fixtures self-cleaned.
- Yoast SEO title-template regression: 24/24 assertions passed.
- Featured-image prompt regression: 8/8 assertions passed.
- First-party Git PHP lint: 36/36 files passed.
- Composer metadata: valid.
- Git documentation links: 59 Markdown files checked with no missing local targets.
- Controller manual-include guard: passed.
- Strong secret-signature scan: passed.
- Whitespace and diff validation: passed.

PHP 8.4 emitted the pre-existing nullable-parameter deprecation for `JobsMetaController::sanitizeDirectiveGenerationCount()` during lint. It is not a syntax failure and is outside the `1.3.6` release scope.

## SVN synchronization and tag validation

Six reviewed distributable files were synchronized byte-for-byte from Git into trunk:

- `Core.php`
- `exmoment-author.php`
- `readme.txt`
- `modules/jobs/JobsExecutionController.php`
- `docs/architecture/prompt-and-author-context.md`
- `docs/modules/jobs-module.md`

The updated Git-only runtime regression, reference index, and this operational release report remain excluded under the established WordPress.org package policy.

- SVN trunk: added `0`, modified `6`, deleted `0`.
- `tags/1.3.6`: one history-preserving copied tree containing 127 packaged files; no deletions or post-copy divergence from trunk.
- Trunk and tag first-party PHP lint: 33/33 files passed in each tree.
- Trunk and tag Composer metadata: valid.
- Trunk and tag documentation links: 49 Markdown files checked in each tree with no missing local targets.
- Trunk/tag recursive byte and SVN property comparison: passed.
- Plugin header, production `resourceVersion`, stable tag, and newest changelog: all `1.3.6` in both trees.
- Packaging contamination audit: no versioned development paths, local environment files, workstation references, strong secret signatures, project tests, archive material, operational release reports, or conflict markers.
- Pending SVN diff trailing-whitespace and secret checks: passed.
- Existing `tags/1.3.5` immutability check: passed; last-changed revision remains `3639646`.

## Publication and post-commit verification

- SVN repository root: `https://plugins.svn.wordpress.org`
- Plugin working-copy URL: `https://plugins.svn.wordpress.org/exmoment-author`
- Trunk URL: `https://plugins.svn.wordpress.org/exmoment-author/trunk`
- Tag URL: `https://plugins.svn.wordpress.org/exmoment-author/tags/1.3.6`
- SVN commit message: `Release version 1.3.6`
- SVN revision: `3643837`
- Remote trunk last-changed revision: `3643837`
- Remote `tags/1.3.6` last-changed revision: `3643837`
- Remote trunk/tag recursive diff: empty.
- Remote trunk/tag packaged files: 127/127.
- Remote plugin headers, stable tags, and newest changelog headings: all `1.3.6`.
- Post-commit working-copy status: clean; only known ignored `.DS_Store` files remain.
- WordPress.org public propagation: complete. The public plugin page reports version `1.3.6`, links to `exmoment-author.1.3.6.zip`, and the Developers section displays the `1.3.6` standalone-title changelog.
