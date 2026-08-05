# Version 1.3.1 Documentation and SVN Release Preparation

## Status

PASS

ExMoment Author 1.3.1 is prepared in the Git working tree and the local WordPress.org SVN working copy. Git and SVN changes remain uncommitted. Nothing was pushed, published, or committed.

## Baseline audit

- Git began clean at `9096aed` (`v1.3.0;`) on `main`.
- The reviewed feature commits were `db8fd38` (WordPress AI Client migration), `7518f8a` (author context and custom job prompts), and `6dcfb5f` (Job Setup tile overflow).
- No runtime commit exists after the 1.3.0 release commit. Version 1.3.1 is therefore a documentation and release-maintenance update, not a re-release of the 1.3.0 runtime features.
- The SVN working copy began clean apart from ignored `.DS_Store` files.
- The committed SVN tag `tags/1.3.0` exists at repository revision `3635706`.
- `tags/1.3.1` did not exist before preparation.
- The repository contains no release script or distribution-ignore manifest. Synchronization follows the committed 1.3.0 tree, reviewed Git changes, and the documented path policy.

## Git and code review scope

The audit reviewed the release metadata and packaging files `exmoment-author.php`, `Core.php`, `readme.txt`, `README.md`, `composer.json`, and `composer.lock`.

Runtime ownership and request behavior were verified against:

- `modules/ai/AiService.php`;
- `modules/gpt/GptController.php` and `modules/gpt/Readme.md`;
- `modules/jobs/JobsAiContextResolver.php`;
- `modules/jobs/JobsExecutionController.php`;
- `modules/jobs/JobsMetaController.php`;
- `modules/jobs/JobsSchedulerWorker.php`;
- `modules/jobs/JobsSchedulingController.php`;
- `modules/settings/SettingsController.php`;
- `modules/settings/views/partials/ai-client.php`;
- `modules/settings/views/partials/ai-setup.php`;
- `resources/src/styles/admin/components/_jobs-meta.scss`;
- `resources/dist/styles/admin/global.css`.

The audit confirmed the real WordPress AI Client DTO boundary (`UserMessage`, `ModelMessage`, and `MessagePart`), provider/model discovery, text/image capability resolution, normalized errors, article/image flows, deterministic prompt ordering, author privacy boundary, custom prompt save contract, shared execution routing, and tile renderer/style ownership.

## Documentation audit and changes

Before this report, 44 existing Markdown documents were inventoried: 42 current canonical/reference documents and two archive documents. Historical reports were preserved instead of rewritten as current architecture.

Eighteen existing documents were updated:

- architecture: `ai-client-migration-audit.md`, `configuration.md`, `index.md`, `javascript-autoload.md`, and `system-overview.md`;
- modules: `ai-service.md`, `gpt.md`, `jobs-module.md`, and `settings-module.md`;
- settings: `ai-setup.md`, `index.md`, and `settings-module.md`;
- operations: `ai-client-migration-plan.md`, `gpt-debug-mode.md`, `index.md`, and `jobs-admin-workflows.md`;
- references: `gpt-module-methods.md` and `index.md`.

Four new canonical documents were created:

- `docs/architecture/ai-request-lifecycle.md`;
- `docs/architecture/prompt-and-author-context.md`;
- `docs/operations/testing-strategy.md`;
- `docs/operations/wordpress-org-release-workflow.md`.

This preparation report is the fifth new document. It is indexed in the Git documentation but intentionally excluded from WordPress.org SVN because the requested final review commands contain a personal workstation path. The release-safe SVN reference index therefore omits this local report entry, and the release-safe SVN documentation hub omits the link to the excluded `docs/archive/` section.

Obsolete or ambiguous material was corrected as follows:

- the pre-migration audit and migration plan are explicitly marked historical and link to the current architecture;
- direct-client, credential, hardcoded allowlist, and provider-specific fallback wording was removed from current module/settings references;
- the current relationship among WordPress AI Client, provider adapter, provider, model, capability, AI Service, feature controller, and resolver is defined once;
- message-array composition is distinguished from the official DTO list required at the AI Client boundary;
- author context, custom prompt resolution, persistence/security rules, image behavior, diagnostics, and Job Setup tile behavior are documented canonically;
- testing and WordPress.org release workflows are now permanent runbooks.

Architecture, operations, and settings indexes were updated for the new canonical documents. The Git references index includes this report. Markdown relative links, folder indexes, and orphan checks pass.

## Version 1.3.1

Authoritative active versions were updated:

- `exmoment-author.php` plugin header: `1.3.1`;
- `Core.php` production `resourceVersion`: `1.3.1`;
- `readme.txt` stable tag: `1.3.1`;
- newest `readme.txt` changelog heading: `1.3.1`.

Composer and JavaScript package metadata do not declare the plugin version. The historical 1.3.0 changelog entry remains intact. No upgrade notice was added because the readme has no established upgrade-notice section and users have no required compatibility action.

The 1.3.1 changelog describes only expanded/corrected technical documentation and release preparation. It does not re-announce the AI migration, author context, custom job prompts, or tile runtime fix already released in 1.3.0.

## Git validation

- Changed PHP syntax checks: PASS (`Core.php`, `exmoment-author.php`).
- Composer validation: PASS (`composer validate --no-check-publish`).
- Git whitespace/error check: PASS (`git diff --check`).
- Explicit trailing-whitespace scan: PASS.
- Markdown relative-link validation: PASS.
- Per-directory index/orphan validation: PASS.
- Active version drift check: PASS; no active distributable declaration remains at 1.3.0.
- Local Docker services: healthy/running.
- Local WordPress plugin version through WP-CLI: `1.3.1`.
- Frontend build/lint: not applicable. No Sass or built CSS changed for 1.3.1, and the repository has no `package.json` frontend command.
- Automated tests: not available in the repository. Focused static, runtime-version, and browser validation were used.

## Browser validation

The authenticated job editor at `http://localhost:8080/wp-admin/post.php?post=32&action=edit` was tested in the in-app browser.

At viewport widths 390, 768, and 1440 pixels:

- document width equaled scroll width;
- all labels remained inside their buttons;
- long text used `overflow: hidden`, `text-overflow: ellipsis`, and `white-space: nowrap` and overflowed only when constrained;
- the short label remained fully visible;
- native `title` values equaled the complete original labels;
- stored directory values were unchanged;
- selected and unselected tile widths matched;
- WordPress button padding remained present.

Click changed the test tile from `aria-pressed="false"`/`button-secondary` to `aria-pressed="true"`/`button-primary is-selected`; Enter restored the original unselected state. No save was submitted.

No ExMoment Author console error appeared. The existing unrelated Yoast `ai-generator.js` error reading `postType` appeared and is not classified as an ExMoment Author regression.

## SVN synchronization

The intended 24 changed distributable files were synchronized from Git to SVN trunk. Seven changed canonical pages were absent from the committed 1.3.0 package. A follow-up packaging review found another 19 untouched canonical pages missing because 1.3.0 contained only the documentation files touched by the migration commits, even though its distributed indexes linked to other pages. Version 1.3.1 completes the already-included documentation policy by adding all 26 previously missing canonical Markdown files.

Canonical documentation is now included from:

- `docs/index.md`;
- `docs/architecture/`;
- `docs/modules/`;
- `docs/settings/`;
- `docs/operations/`;
- `docs/references/`.

`docs/archive/` remains excluded. The release-safe SVN `docs/index.md` omits its archive link. This workstation-specific preparation report and its Git-only reference-index entry remain excluded. Top-level WordPress.org `assets/` were untouched, and development-only files were not copied.

SVN trunk status:

- files added: 30;
- files modified: 13;
- files scheduled for deletion: 0.

The 30 added files comprise four new canonical documents and 26 canonical documents that existed in Git but were missing from the 1.3.0 SVN package. All synchronized files match their intended Git source bytes except the two release-safe indexes, whose only differences are removal of links to the deliberately excluded archive and local report.

## Local SVN tag and parity

`tags/1.3.1` was created locally with `svn copy` from the fully updated local trunk. It is scheduled for addition with copy history and has not been committed. `tags/1.3.0` was not modified.

Deterministic trunk/tag validation passed:

- recursive path and byte comparison: identical, excluding only SVN administration data and ignored `.DS_Store` working-copy noise;
- recursive SVN property comparison: identical;
- plugin header, production asset version, stable tag, and newest changelog: all `1.3.1`;
- historical 1.3.0 changelog entry: preserved;
- active 1.3.0 release declaration: none;
- Composer validation: passed in trunk and tag;
- changed PHP syntax checks: passed in trunk and tag.

Ignored `.DS_Store` files exist physically in the local working copy but are not versioned and will not be published.

## Packaging and security review

- Personal `/Users/` path scan of the versioned release tree: no matches.
- Credential/private-key signature scan: no matches.
- Sensitive-term matches were reviewed and are legitimate option names, validation/security guidance, token-budget terminology, or sanitized diagnostic code; no real credential was found.
- `localhost` matches are limited to the development-host logic and local developer setup documentation; `127.0.0.1` is not shipped as a credential or provider endpoint.
- No `.env`, database dump, log, credential, temporary browser output, generated media fixture, coverage output, or local Docker configuration was added to SVN.
- Composer dependencies and lockfiles were not changed.
- The Git-only report is excluded from SVN specifically to avoid shipping its required workstation-specific review paths.

## Remaining warnings and limitations

- The repository has no automated unit/integration suite or frontend package lint command.
- No Sass rebuild was needed because 1.3.1 changes no stylesheet source.
- Provider-backed article, metadata, and image generation were not rerun because 1.3.1 contains no runtime AI change and those checks can consume sources or create posts/media. Their 1.3.0 evidence remains in the historical implementation reports.
- The browser interaction intentionally did not submit the job form, so persisted selection state was not altered.
- Git and SVN remain intentionally uncommitted.

## Review commands

```bash
cd /Users/gergannikolov/Desktop/NewBeam/exmoment-author
git status
git diff --check
git diff
```

```bash
cd /Users/gergannikolov/Desktop/NewBeam/SVN/exmoment-author-svn
svn status
svn diff
```

```bash
svn commit -m "Release version 1.3.1"
```
