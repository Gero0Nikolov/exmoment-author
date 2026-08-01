# Library Module

## Scope

Library module manages reusable content bundles and used-article tracking.

## Components

- `modules/library/LibraryController.php`
- `modules/library/UsedArticlesRepository.php`
- `modules/library/views/index.php`

## Responsibilities

- Provide admin UI for categories/files.
- Handle library AJAX actions (list, preview, rename, delete, upload).
- Track and enforce used-article registry constraints.

## Upload Archive Contract

- Accept ZIP archives up to the configured 10 MB upload limit.
- Accept `.txt` and `.md` article files.
- Accept article files directly at the archive root. For this layout, the
  sanitized ZIP filename becomes the library category name.
- Continue accepting the existing layout containing exactly one top-level
  category directory with article files directly inside it.
- Reject nested directories, mixed root/directory layouts, traversal or
  absolute paths, symbolic links, hidden entries other than known system
  metadata, and unsupported file types.
- Store imported articles under
  `uploads/exmoau-library/<category>/<article>`.
