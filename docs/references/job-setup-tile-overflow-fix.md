# Job Setup Tile Overflow Fix

## Outcome

Long directory and content-pack labels now remain within their job setup tiles and display a single-line ellipsis when the available content width is insufficient. The complete label remains available through the button's native `title` attribute.

## Root Cause

The tile already occupied its grid column at `width: 100%`, but it was not a flex container and its label had no shrink or overflow rules. Long, unbroken names could therefore render through the button's content box instead of shrinking inside the existing padding.

## Rendering Path

`JobsMetaController::buildMixturePanelMarkup()` is the shared renderer for directory tiles. It supplies both the initial markup and the HTML returned by the Mixture-tab AJAX endpoint. Client-side code injects that returned fragment and binds state handlers; it does not independently construct tiles.

The renderer adds the complete label to `title` with `esc_attr()` while continuing to escape visible text with `esc_html()`. Existing values, data attributes, classes, selection state, and ARIA state are unchanged.

## Styling

The tile is now a full-width flex container with `min-width: 0`. Its label uses `flex: 1 1 auto`, `min-width: 0`, `overflow: hidden`, `text-overflow: ellipsis`, and `white-space: nowrap`.

These rules constrain text to the content box after WordPress button padding. No fixed width, character truncation, spacing change, or global WordPress button override was introduced.

The source change is in `resources/src/styles/admin/components/_jobs-meta.scss`, and the corresponding generated rule is present in `resources/dist/styles/admin/global.css`.

## Browser Verification

The authenticated WordPress job editor was exercised in the in-app browser with a temporary long-name directory. A same-origin iframe harness rendered the real editor at exact viewport widths of 390px, 768px, and 1440px.

At every width:

- document client width equaled document scroll width;
- the long label was wider than its visible label box and used ellipsis styling;
- the label remained within the button bounds;
- the complete label matched the `title` attribute;
- the short label remained fully visible;
- selected and unselected tiles had equal widths;
- existing horizontal padding remained present.

Click and Enter-key activation toggled `aria-pressed`, `button-primary`, and `button-secondary` correctly. Mixture/Directive AJAX tab changes and all three job-type selections retained one correctly styled tile and preserved the existing saved directory selection. The temporary directory and responsive harness were removed after verification.

No ExMoment Author console errors were introduced. The existing Yoast `ai-generator.js` `postType` error appeared independently in each editor frame.

## Build and Static Checks

The Sass source compiled successfully using the repository's configured Sass version. A temporary import alias was required because `global.scss` already refers to `_exmoau-log.scss` while the repository contains `_exmoment-author-log.scss`; that unrelated naming mismatch was not changed. The generated tile rules were propagated to the built admin CSS without a package or lockfile change.

PHP syntax validation and `git diff --check` passed. The repository has no dedicated frontend test or lint script.
