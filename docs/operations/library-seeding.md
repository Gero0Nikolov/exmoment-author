# Library Seeding

## Scope

Activation-time extraction of bundled archives into uploads storage.

## Runtime Source

- Core activation flow in `Core.php`.

## Checklist

1. Ensure `ZipArchive` extension exists.
2. Verify source archive directory is readable.
3. Verify destination uploads path is writable.
4. Confirm protection files (`index.php`, `.htaccess`) exist.
5. Review seeding summary logs for extracted/skipped counts.
