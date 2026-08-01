# Library Admin UI

## Scope

Operational usage of the library management interface.

## Typical Tasks

- Browse categories/files.
- Preview stored content.
- Rename or delete entries safely.
- Upload library bundles.

## Validation Points

- Ensure category/file identifiers are valid.
- Confirm filesystem permissions are safe.
- Verify audit/debug logs for failed operations.

## Uploading Content Packs

1. Prepare one ZIP containing `.txt` or `.md` article files.
2. Place the article files either directly at the ZIP root or directly inside
   one top-level category directory. Do not mix the two layouts.
3. When files are at the ZIP root, name the ZIP for the category that should be
   created. The uploader sanitizes the filename before using it as the category.
4. Upload the ZIP from **Tools → ExMoment Author Library**.
5. Confirm the new category appears and lists the imported article files.

Nested directories, absolute or traversal paths, symbolic links, unexpected
hidden entries, and unsupported file types are rejected before import.
