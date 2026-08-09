# Article Categorisation Audit and Fix Report

## Final status

**PASS** — article categorisation now follows one canonical integration path:

```text
current WordPress category allowlist
→ mandatory AI selection contract
→ exact category slug list
→ strict original-allowlist validation
→ current taxonomy lookup
→ directly selected term IDs
→ complete WordPress ancestor chains
→ deterministic hierarchy deduplication
→ post_category
```

The AI makes the semantic decision and returns only the most specific matching slug or slugs. The plugin owns validation, ancestor expansion, and WordPress assignment. No fuzzy matching, arbitrary name/ID resolution, category creation, sibling expansion, first-item fallback, or random balancing remains.

## Why the previous implementation was insufficient

The previous patch fixed missing category assignment by treating actual library source-directory labels as category references. `JobsArticleCategoryResolver` then attempted to resolve each reference as an existing term ID, exact slug, or normalized exact name.

That implementation was deterministic after source collection, but it did not implement the intended product decision boundary:

- WordPress categories were not supplied to the AI.
- The AI response contained no category field.
- Library directory labels—not the completed article—made the category decision.
- The resolver supported three authoritative identifier types: ID, slug, and name.
- Source labels that happened to resemble taxonomy values could determine assignment even when the completed article fit another existing category better.
- The multi-mode resolver created a competing path beside the required allowlisted-slug mechanism.

The rework removes that competition. Library labels remain source provenance for editorial context only. They are no longer taxonomy assignment inputs.

### Parent-hierarchy follow-up

The exact-slug rework initially assigned only the term directly selected by the AI. That was safe and deterministic, but WordPress does not automatically check a category's parents when a child ID is supplied through `post_category`. A selected child such as `debt` therefore appeared without its parent hierarchy.

This follow-up keeps the AI selection boundary unchanged and adds hierarchy expansion only after the selected slug has passed exact allowlist and current-taxonomy validation. The AI still returns the most specific slug; PHP adds every legitimate ancestor.

## Final architecture

### 1. Build the category allowlist

`JobsArticleCategoryResolver::getAvailableCategories()` queries the current `category` taxonomy with `hide_empty` disabled. It accepts only valid `WP_Term` category objects, validates each canonical slug, removes duplicate slugs, preserves child terms as independent options, and sorts records by slug for stable request payloads.

Each AI-facing record contains only:

```json
{
    "slug": "wealth-building",
    "name": "Wealth Building"
}
```

The name improves semantic accuracy but is descriptive data only. The slug is the sole authoritative identifier. Term IDs and WordPress objects are not transmitted.

### 2. Add a mandatory AI contract

`JobsExecutionController::buildMessages()` places the category contract before the replaceable editorial prompt:

1. mandatory article/SEO/category response protocol;
2. mandatory WordPress category-selection contract and JSON allowlist;
3. effective global or job-specific editorial prompt;
4. optional author context;
5. source documents as separate user messages.

A custom job prompt can replace only item 3. It cannot remove the category contract.

The contract tells the model:

- supplied categories are the complete authoritative allowlist;
- return only exact supplied slug values;
- select only the most specific appropriate slug when related parent and child categories are available;
- never invent, rewrite, translate, normalize, or approximate a slug;
- prefer one category;
- use multiple categories only for a genuinely multi-topic article;
- return an empty list only when nothing fits;
- return no category prose or markdown.

### 3. Extend the structured response

The existing hidden metadata block now has four required single-line fields:

```text
SEO_TITLE: <plain title>
SEO_DESCRIPTION: <plain description>
FOCUS_KEYPHRASE: <plain keyphrase>
CATEGORY_SLUGS_JSON: ["exact-existing-slug"]
```

`CATEGORY_SLUGS_JSON` must decode to a JSON list. A scalar JSON string, JSON object, comma-separated string, malformed JSON, prose, missing field, or duplicate field is rejected with a stable category-specific parser error.

Article title/body and the three Yoast fields keep their existing contract and parser behavior.

### 4. Validate the untrusted selection

`JobsArticleCategoryResolver::resolve()` validates the parsed list against the exact slug snapshot sent in that request.

For every returned value it enforces:

- the outer selection is a JSON-style list;
- each item is a string;
- no leading/trailing whitespace;
- non-empty WordPress canonical slug format;
- maximum length of 200 characters;
- exact membership in the original request allowlist;
- deduplication without using list position as meaning;
- current resolution through `get_term_by('slug', ..., 'category')`;
- exact current taxonomy and slug identity;
- a positive current term ID.

Unknown, malformed, missing, or deleted-between-request-and-assignment values are rejected. The resolver does not sanitize an invalid value into a match, accept a category name, fuzzy-match a similar slug, create a term, or substitute another category.

### 5. Expand selected terms to their ancestors

After direct selected slugs resolve to legitimate `category` terms, `JobsArticleCategoryResolver` calls WordPress's `get_ancestors()` taxonomy API for every selected term. Each returned ancestor is revalidated as a current `WP_Term` in the `category` taxonomy.

The final ID list is constructed deterministically:

- selected branches are sorted by canonical selected slug, not AI or allowlist position;
- each branch is emitted top-level ancestor → intermediate parent → selected child;
- shared ancestors are included only once;
- an explicitly selected parent is not also reported as an automatically added ancestor;
- missing or inconsistent ancestors are rejected without inventing replacements or adding siblings/default categories.

The resolver exposes distinct `selected_term_ids`, `ancestor_term_ids`, and final `term_ids` values for diagnostics and assignment.

### 6. Assign only validated hierarchy IDs

The selected term and validated ancestor IDs pass through the existing immediate pre-insert term check and then enter `post_category`. A top-level selection therefore assigns only itself, while a child or grandchild selection assigns its complete root-to-leaf hierarchy. When no valid selection remains, the plugin supplies no category IDs, emits a distinct `jobs.categorisation` warning, and leaves WordPress's configured default-category behavior unchanged. It does not select the first allowlist term.

## Diagnostics

Safe category diagnostics are separate from provider, article-parser, and post-insertion failures.

Debug traces cover:

- job ID;
- exact available slug list;
- parsed selected slugs;
- rejected slugs;
- directly selected term IDs;
- automatically added ancestor IDs;
- ancestor-resolution errors;
- final assigned category IDs;
- stable error code.

Persistent `jobs.categorisation` warnings cover allowlist failures, empty/invalid selection, partial rejection, rejection values/reasons, and resolved IDs. No credentials, provider secrets, complete source documents, or full prompts are logged.

## Removed and replaced behavior

Removed from `JobsArticleCategoryResolver`:

- numeric term-ID inputs;
- category-name inputs;
- normalized name matching;
- HTML-entity name normalization;
- ID/name/slug lookup indexes;
- ambiguous duplicate-name handling;
- source-directory references as assignment inputs.

Removed from `JobsExecutionController`:

- collecting category references from source article records;
- post-generation source-context taxonomy resolution;
- source-reference/ambiguity warning payloads.

Replaced with:

- current category slug/name allowlist;
- mandatory AI slug-selection contract;
- typed JSON category metadata;
- exact original-allowlist validation;
- current taxonomy lookup by slug;
- WordPress-owned complete ancestor expansion;
- deterministic root-to-leaf ordering and shared-ancestor deduplication;
- selected/rejected slug diagnostics.

## Affected files

- `modules/jobs/JobsArticleCategoryResolver.php` — current allowlist construction and strict AI-slug resolution.
- `modules/jobs/JobsExecutionController.php` — mandatory prompt contract, response parsing, validation orchestration, diagnostics, and assignment.
- `tests/runtime/article-categorisation-regression.php` — self-cleaning 47-assertion WordPress regression suite.
- `docs/modules/jobs-module.md` — maintained jobs/category behavior.
- `docs/architecture/prompt-and-author-context.md` — mandatory prompt ordering and category integration boundary.
- `docs/references/article-categorisation-audit-and-fix-report.md` — this implementation and validation record.

`Core.php` keeps the existing non-instantiated autoload entry for `JobsArticleCategoryResolver`; no second categorisation component was added.

## Automated regression coverage

The replacement suite runs through WP-CLI:

```bash
wp eval-file tests/runtime/article-categorisation-regression.php
```

All 47 assertions passed. Coverage includes:

- current WordPress allowlist retrieval;
- valid top-level slug;
- another top-level slug;
- child-category slug;
- direct child selection plus automatic parent assignment;
- grandchild selection plus complete ancestor assignment;
- two children sharing one deduplicated parent;
- multiple independent hierarchy branches;
- deterministic branch ordering independent of AI selection order;
- multiple valid slugs;
- duplicate returned slugs;
- unknown slug;
- category name returned instead of slug;
- malformed non-array selection;
- empty selection;
- category deleted after allowlist creation;
- stable deleted-term rejection reason;
- allowlist-order independence;
- explicit proof that the first allowlist entry is not assigned;
- explicit proof that invalid selections trigger no ancestor expansion;
- mandatory contract presence;
- exact slug/name payload presence;
- global and custom editorial prompts retained;
- author context enabled and disabled;
- valid structured category JSON parsing;
- scalar structured value rejection;
- duplicate category metadata rejection;
- category parse errors isolated from SEO validation errors;
- selected child/grandchild and complete validated ancestors assigned;
- no new term creation;
- category-specific warning persistence.

The suite creates uniquely named terms, posts, and one log fixture, then removes them. The category taxonomy and generated-post set returned to their pre-test state.

## Runtime AI validation

### Test configuration

Date: 2026-08-09

The configured local WordPress environment used:

- job 32, `Single > Instant #2`;
- provider `openai` through the WordPress AI Client;
- model `gpt-5-nano`;
- Manual behavior with the global/manual editorial prompt;
- empty job custom prompt;
- author context enabled;
- target post type `post`;
- target status `draft`;
- configured author ID 1 (`admin`);
- one real debt source from `content-pack-debt-1-50`.

For the validation window, the existing local terms were arranged as a three-level hierarchy:

```text
Finance (term 50)
└── Personal Finance (term 51)
    └── Debt (term 28)
```

`Debt` was restored to its original top-level parent value immediately after Browser verification. Job uniqueness was temporarily disabled, articles-per-category was reduced to one, and featured-image generation was disabled through the existing runtime filter. Original job metadata and the one touched used-source registry row were restored after the request.

### Available categories sent to AI

The actual allowlist records were:

```json
[
    {
        "slug": "debt",
        "name": "Debt"
    },
    {
        "slug": "finance",
        "name": "Finance"
    },
    {
        "slug": "personal-finance",
        "name": "Personal Finance"
    },
    {
        "slug": "uncategorized",
        "name": "Uncategorized"
    }
]
```

The AI was not asked to repeat `finance` or `personal-finance`. It continued to select only the most specific matching slug.

### AI response and assignment trace

| Field | Expected | Actual |
| --- | --- | --- |
| Returned `categorySlugs` value | `debt` | `debt` |
| Rejected slugs | none | none |
| Direct selected term IDs | `[28]` | `[28]` |
| Automatically added ancestor IDs | `[50, 51]` | `[50, 51]` |
| Final assigned category IDs | `[50, 51, 28]` | `[50, 51, 28]` |
| `Uncategorized` assigned | no | no |

The structured execution result reported:

```json
{
    "available_category_slugs": [
        "debt",
        "finance",
        "personal-finance",
        "uncategorized"
    ],
    "category_slugs": ["debt"],
    "selected_category_ids": [28],
    "ancestor_category_ids": [50, 51],
    "category_ids": [50, 51, 28],
    "rejected_category_slugs": []
}
```

The provider-backed run created temporary draft post 100, titled **Three Ways to Avoid Bankruptcy**. WordPress term inspection confirmed the final post categories were Finance, Personal Finance, and Debt with their expected parent relationships.

## Browser validation

Temporary post 100 was opened in the authenticated local WordPress block editor through the explicitly requested in-app Browser.

The editor showed:

- title **Three Ways to Avoid Bankruptcy**;
- status **Draft**;
- author **admin**;
- 573 editor-counted words;
- Yoast SEO analysis **OK**;
- readability analysis **Good**;
- `Finance` checked;
- `Personal Finance` checked;
- `Debt` checked;
- `Uncategorized` unchecked.

The checked states are the authoritative UI confirmation that one AI-selected leaf slug produced the complete WordPress hierarchy without adding an unrelated/default category. The category count remained four; no terms or duplicates were created.

After Browser verification, post 100 was deleted, `Debt` was restored to parent `0`, job 32's previous result pointer (post 81) was restored, the used-source registry change was reverted, and the temporary validation state was removed. Final checks confirmed four categories, no post 100, and no remaining validation option or fixture.

## Regression assessment

| Area | Status | Evidence |
| --- | --- | --- |
| AI semantic category decision | PASS | Real `gpt-5-nano` run selected `debt` from the supplied list. |
| Exact allowlisting | PASS | Unknown/name/malformed/deleted values rejected in runtime tests. |
| No first-category fallback | PASS | Automated coverage proves allowlist order cannot substitute another selected or ancestor term. |
| No category creation | PASS | Term count unchanged after invalid selection tests. |
| Parent/child handling | PASS | Browser showed Finance, Personal Finance, and AI-selected Debt checked from one leaf slug. |
| Grandchild handling | PASS | Direct ID 28 expanded to ancestor IDs 50 and 51 in root-to-leaf order. |
| Multiple categories | PASS | Shared-parent and independent-branch tests expand both selections and deduplicate ancestors. |
| Custom/global prompts | PASS | Mandatory contract survives both prompt paths in automated coverage. |
| Author context on/off | PASS | Mandatory contract survives both author-context states. |
| Article/title/SEO | PASS | Provider-backed post and Yoast fields remained valid. |
| Post author/status | PASS | Browser showed `admin` and Draft. |
| Scheduling architecture | PASS by shared-path review | Instant and scheduled entry points still converge on `executeJob()`. |
| Image generation | PASS by isolation/regression | Runtime validation disabled the unrelated image step; the existing featured-image regression remains green. |

## Conclusion

The final implementation gives the AI a current, explicit WordPress category allowlist and requires only the most specific exact canonical slug output, while treating that output as untrusted at every plugin boundary. The plugin validates against the original request snapshot, verifies current taxonomy existence, resolves direct IDs, expands them through WordPress's ancestor API, revalidates and deduplicates each hierarchy, and assigns the deterministic root-to-leaf result. Diagnostics distinguish direct selections, automatic ancestors, and final IDs without inventing, approximating, creating, adding siblings/defaults, or falling back to the first category.
