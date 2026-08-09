# Version 1.3.4 SVN Release Report

## Final status

**PASS** — ExMoment Author `1.3.4` was published to WordPress.org SVN at revision `3639544`. The committed remote trunk and tag are identical, the working copy is clean, the prior `1.3.3` tag remains unchanged, and WordPress.org has propagated version `1.3.4` and its changelog.

## Release version and scope

- Release: `1.3.4`
- Scope: AI-selected WordPress category slugs, strict request-allowlist validation, deterministic taxonomy resolution, and automatic complete ancestor assignment for selected child categories.
- Git commit: intentionally not created by this release workflow.
- WordPress.org package policy: runtime plugin files and release-safe canonical documentation only; tests, local tooling, archive material, and operational release reports remain excluded.

The canonical categorisation path included in this release is:

```text
WordPress category allowlist
→ AI selects exact specific slug(s)
→ strict plugin validation
→ directly selected term IDs
→ WordPress ancestor expansion
→ deterministic root-to-leaf deduplication
→ post_category
```

## Categorisation changes

- The generation request supplies current category slug/name records as an authoritative allowlist.
- The AI returns only exact selected slugs in the existing structured metadata field.
- The plugin rejects malformed, unknown, deleted, name-based, approximate, and non-allowlisted values without category creation or fallback.
- Directly selected child terms expand through WordPress's taxonomy ancestor API.
- Multiple hierarchy branches are deterministic and shared ancestors are deduplicated.
- Diagnostics distinguish selected slugs, direct term IDs, automatically added ancestors, rejected values, hierarchy errors, and final category IDs.

Real pre-release validation confirmed an AI selection of `debt`, direct selected ID `[28]`, ancestor IDs `[50, 51]`, and final assignment `[50, 51, 28]`. The WordPress editor showed Finance, Personal Finance, and Debt checked while Uncategorized remained unchecked.

## Version files and changelog

The authoritative production locations were updated to `1.3.4`:

- plugin header in `exmoment-author.php`;
- production `resourceVersion` in `Core.php`;
- `Stable tag` in `readme.txt`.

The `1.3.4` WordPress.org changelog summarizes exact allowlisted AI category selection, strict deterministic assignment, complete ancestor inclusion, removal of the obsolete source-label/ID/name resolver path, and expanded regression coverage.

## Git source validation

- Categorisation regression: 47/47 assertions passed; fixtures self-cleaned.
- Featured-image regression: 8/8 assertions passed.
- First-party PHP lint: 35/35 files passed.
- Composer metadata: valid.
- Documentation links: 53 Markdown files checked with no missing local targets.
- Whitespace and diff validation: passed.

The release source review includes both the previously staged categorisation baseline and the newer unstaged hierarchy follow-up. The SVN package is synchronized from the complete working-tree files, not the Git index alone.

## SVN synchronization and package validation

- Trunk synchronization: eight reviewed distributable files copied byte-for-byte from the complete Git working tree.
- Added SVN paths: one history-preserving `tags/1.3.4` copied tree containing 127 packaged files; no standalone distributable additions.
- Modified SVN files: eight files in trunk, mirrored as local modifications within the copied tag.
- Deleted SVN paths: zero.
- `tags/1.3.4` copy-history creation: passed; copied from trunk at working-copy revision 3639541.
- Trunk/tag path, byte, and property parity: passed.
- Trunk and tag PHP lint: 33/33 first-party files passed in each tree.
- Trunk and tag Composer metadata: valid.
- Trunk and tag documentation links: 48 Markdown files checked in each tree with no missing local targets.
- Packaging contamination audit: passed with no prohibited development paths, workstation paths, strong secret signatures, test suite, Docker configuration, database/log files, or operational release report.
- Pending SVN diff whitespace validation: passed.
- `tags/1.3.3` immutability check: passed; no status or diff under the existing tag.

Local WordPress recognized ExMoment Author as version `1.3.4` through both WP-CLI and the authenticated Plugins screen.

Post-commit remote export validation confirmed:

- 127 packaged files in both trunk and tag;
- recursive path and byte parity;
- 33/33 first-party PHP files passed lint;
- valid Composer metadata;
- zero prohibited paths, workstation paths, or strong secret signatures.

This operational report and its Git reference-index link are intentionally excluded from WordPress.org SVN. They are not required for the distributable plugin and record release-process details rather than product documentation.

## Publication

- SVN commit message: `Release version 1.3.4`
- SVN revision: `3639544`.
- Remote trunk: committed at revision `3639544`.
- Remote `tags/1.3.4`: committed at revision `3639544` with copy history from trunk.
- Post-commit working-copy status: clean.
- Prior `tags/1.3.3`: unchanged; last-changed revision remains `3639259`.
- WordPress.org propagation: complete. The public plugin page reports software version `1.3.4`, links to `exmoment-author.1.3.4.zip`, and the Developers/changelog and Meta surfaces display `1.3.4`.
