# JavaScript Autoload

Admin-side JavaScript is registered by core and localized for module scripts.

## Asset Layout

- Base script root: `resources/src/scripts/`
- Autoload scripts: `resources/src/scripts/autoload/admin/`
- Dependency scripts: `resources/src/scripts/dependencies/admin/`
- Compiled admin styles: `resources/dist/styles/admin/`

## Core Loading

Core registers/enqueues:

- `core-admin.js`
- `global.css`

And localizes runtime values (API base, nonce, admin URLs, module options) used by admin UIs.

## Change Guidance

- Keep new admin scripts under existing resource conventions.
- Reuse core localization payload instead of duplicating global JS globals.
- Document any new autoloaded script in module docs and this page when architecture changes.
