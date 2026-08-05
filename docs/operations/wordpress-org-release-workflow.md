# WordPress.org Release Workflow

This runbook documents the repository's current Git-to-SVN release process. It prepares a local SVN working copy; publishing remains a separate, explicit action.

## Repositories and distribution policy

- Git source: the repository root containing `exmoment-author.php`.
- WordPress.org SVN working copy: the release operator's checkout of `https://plugins.svn.wordpress.org/exmoment-author`.
- Release trunk: `trunk/`
- Immutable release snapshots: `tags/<version>/`
- WordPress.org listing assets: top-level `assets/`, outside `trunk/`

The committed 1.3.0 package establishes the current distribution policy. Runtime files, compiled assets, screenshots, Composer production files, and canonical documentation under `docs/architecture/`, `docs/modules/`, `docs/settings/`, `docs/operations/`, and `docs/references/` are distributed. Historical `docs/archive/` material is not in SVN. Development-only root files and directories—including `.git/`, `.github/`, `.env*`, `AGENTS.md`, local Docker configuration, `ai-actions/`, `output/`, caches, test output, IDE metadata, and private reports—are excluded. Top-level SVN `assets/` must be preserved and must not be replaced by the plugin's `resources/` or `screenshots/` directories.

Do not silently broaden or narrow this policy. If a release needs a new path category, review it explicitly before synchronization.

## 1. Establish a clean baseline

Inspect Git status and the commits since the last release. Inspect `svn status --no-ignore`, confirm only known ignored files are present, and verify the prior tag is committed. Confirm the target tag does not already exist; stop on conflict.

Identify authoritative active version locations from source rather than applying a global replacement. At present they are:

- `Version:` in `exmoment-author.php`;
- production `resourceVersion` in `Core.php`;
- `Stable tag:` in `readme.txt`;
- the newest `readme.txt` changelog heading.

Composer and JavaScript package metadata do not currently declare a plugin version. Historical changelog entries must remain unchanged.

## 2. Prepare and validate Git source

Update the active version declarations and add a changelog entry containing only work completed since the previous release. Add an upgrade notice only when the readme already uses that section and users need a concrete migration action.

Validate the Git source using [Testing Strategy](testing-strategy.md). Review the full diff and confirm the change set contains no credentials, local paths, temporary data, generated fixtures, or unrelated dependency changes. If Sass changed, compile and review the built CSS before continuing.

## 3. Synchronize intended distributable paths

Use the reviewed Git diff or an explicit release manifest as the synchronization source. Copy only distributable changed files into `trunk/`. Schedule new distributable paths with `svn add`; schedule obsolete distributable paths with `svn delete`. Do not recursively replace the entire SVN working copy, because top-level WordPress.org assets and established exclusions are not Git release inputs.

Canonical documentation is intentionally distributed under the five documented `docs/` sections. `docs/archive/` remains excluded unless policy is explicitly changed.

After copying, compare every manifest path against Git. Confirm current files are byte-identical and removed files are scheduled for deletion. Inspect `svn status` and `svn diff` before tagging.

## 4. Create the local tag

Create `tags/<version>` with a working-copy-to-working-copy `svn copy` from the fully updated local trunk. This preserves SVN copy history while including the reviewed local trunk changes. Do not edit an older release tag and do not create the remote tag independently.

Do not run `svn commit` unless publishing has been separately authorized.

## 5. Validate parity and packaging

Perform a recursive comparison between `trunk/` and the new tag, excluding only administrative `.svn` directories. A matching count is insufficient: paths and file bytes must match. Compare relevant SVN properties for each versioned path and verify that additions/deletions are represented in status.

Check both trees for:

- plugin header, runtime asset version, stable tag, and newest changelog version;
- no active declaration of the prior release;
- valid Composer metadata and PHP syntax;
- no credentials, private keys, personal filesystem paths, temporary fixtures, local database files, or unintended Docker/development files.

Ignored `.DS_Store` files are local working-copy noise and must remain unversioned.

## 6. Review and publish separately

Review locally:

```bash
cd <path-to-exmoment-author-git>
git status
git diff --check
git diff
```

```bash
cd <path-to-exmoment-author-svn>
svn status
svn diff
```

Only after approval, publishing would use a command such as:

```bash
svn commit -m "Release version X.Y.Z"
```

The preparation workflow does not execute that command.
