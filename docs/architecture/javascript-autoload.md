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

## Stylesheet Source and Build Boundary

Admin Sass sources live under `resources/src/styles/admin/`; the distributable compiled stylesheet is `resources/dist/styles/admin/global.css`. Edit the source component first, run the established Sass/Prepros-compatible build, and review the generated diff. Do not patch only the minified output.

Job Setup directory tiles are rendered by `JobsMetaController::buildMixturePanelMarkup()`. The scoped `.exmoau-job-setup__tile` and `.exmoau-job-setup__tile-label` rules live in `resources/src/styles/admin/components/_jobs-meta.scss`. They preserve WordPress button padding while a flex child applies single-line ellipsis. The renderer exposes the complete escaped label in the button's native `title` attribute; its directory value and selection state are independent of presentation.

The repository has no `package.json` frontend test or lint script. Record the exact Sass command used for a release and do not introduce dependency or lockfile changes merely to rebuild existing assets.
