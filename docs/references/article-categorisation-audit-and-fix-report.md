# Article Categorisation Audit and Fix Report

## Final status

**PASS** — generated posts now receive only validated WordPress categories that exactly match their actual library source-category context. Category order no longer influences resolution. Failed resolution is explicit and logged, with WordPress's configured default-category behavior left intact.

## Observed bug

The local site initially had one category:

| Term ID | Name | Slug | Parent | Post count |
| ---: | --- | --- | ---: | ---: |
| 1 | Uncategorized | `uncategorized` | 0 | 11 |

All 11 categorized posts used term ID 1. The WordPress `default_category` option was also 1.

The affected generated article selected for the end-to-end trace was draft post 60, **From Debt to Investing: A Practical Path**. It was the last result of job 32, **Single > Instant #2**, whose selected library directory was `content-pack-debt-1-50`. Post 60 had only category term ID 1 (`Uncategorized`).

The initial symptom looked like first-category bias, but the audit found no first-term selection code. The defect was more fundamental: ExMoment Author did not perform WordPress category resolution or assignment at all.

## Reproduction and classification

Browser inspection of `Posts > Categories`, the jobs list, job 32, and the draft posts list established the following:

- job 32 selected `content-pack-debt-1-50` under Setup → Mixture;
- the job targeted WordPress posts, draft status, author ID 1, and five articles per category;
- post 60 was the job's stored last-result post;
- post 60 was categorized as `Uncategorized`;
- every other categorized post on the baseline site also used term ID 1.

The classification is **F: category assignment was absent**. It was not A or B because ExMoment Author never selected the first WordPress or top-level term. It was not C because there was no ExMoment category matcher with a first-item fallback. It was not D because the AI response contract contained no category field. It was not E because no category matcher existed.

WordPress applied its configured default category because `wp_insert_post()` received no `post_category` value. On this site, that default happened to be the only and first visible category.

## End-to-end affected path

The traced path for job 32 and post 60 was:

```text
Job 32 meta
exmoau_setup_mixture_directories = [content-pack-debt-1-50]
    ↓
JobsExecutionController::collectSources()
article.category = content-pack-debt-1-50
    ↓
JobsExecutionController::buildMessages()
Source: content-pack-debt-1-50/<filename>
    ↓
GptController::chatCompletionCreate()
AI response contains article + hidden SEO metadata, no category field
    ↓
JobsExecutionController::parseArticleResponse()
parses title, body, seo_title, seo_description, focus_keyphrase only
    ↓
JobsExecutionController::createPost()
previously supplied title, content, type, status, and author only
    ↓
wp_insert_post()
WordPress applies default category term ID 1
```

The raw historical AI response for post 60 was not retained in logs. That does not leave the category transition ambiguous: the prompt contract, parser, post payload, stored job metadata, stored post relationship, and a new full provider-backed run all agree that category data never came from the model.

## Previous categorisation behavior and fallback audit

Before the fix:

- WordPress categories were not loaded by the generation path.
- Library categories were represented as sanitized directory strings.
- Each source message included its directory string for editorial context.
- The AI was not given a WordPress category allowlist, ID, name, or slug.
- The AI response schema did not request or accept category output.
- The response parser did not parse a category value.
- No normalization, term lookup, parent/child handling, or category matcher existed.
- `createPost()` did not include `post_category` and did not call `wp_set_post_categories()`.
- WordPress's own default-category behavior was the only fallback.

The audit found no relevant use of array index 0, `reset()`, `current()`, a first `foreach` result, substring matching, or parent-category fallback in the generated-post category path. Other occurrences of these constructs belonged to unrelated numeric form sanitization, source logging, or library registry behavior.

## Intended category model

The existing product model treats selected library directories as the job's category/source context. Actual source articles retain that directory value through collection and prompt construction. The AI contract is deliberately limited to article content and SEO metadata.

The fix preserves that model:

- actual source-category references drive WordPress term resolution;
- AI prompts and editorial behavior remain unchanged;
- the AI does not invent or select WordPress categories;
- one source category may resolve to one term;
- several legitimate source categories may resolve to several terms;
- a child category remains the resolved child rather than collapsing to its parent.

## Root cause

`JobsExecutionController::createPost()` omitted `post_category`. No preceding code resolved the collected library-category strings to WordPress category terms. WordPress therefore applied the `default_category` option to every generated post whose target post type used the category taxonomy.

The observed concentration was a deterministic consequence of the missing assignment, not biased randomness, faulty AI output, or incorrect loose matching.

## Corrected behavior

`JobsArticleCategoryResolver` now:

1. loads all existing terms from the `category` taxonomy with `hide_empty` disabled;
2. builds lookup indexes by term ID, normalized exact slug, and normalized exact name;
3. prefers an exact existing term ID for numeric references;
4. decodes HTML entities, normalizes whitespace, and compares names case-insensitively;
5. compares slugs case-insensitively without substring or fuzzy matching;
6. rejects ambiguous duplicate-name matches rather than choosing by query order;
7. validates every resolved ID with `get_term($id, 'category')`;
8. preserves child IDs and all unique legitimate matches;
9. never creates, renames, or duplicates terms.

`JobsExecutionController` collects category references only from the actual source articles used in the run. It validates resolved IDs again immediately before insertion and adds `post_category` only when at least one validated ID exists.

When nothing resolves, the controller records a persistent `jobs.categorisation` warning containing sanitized references, resolved IDs, unresolved values, ambiguity data, and a stable error code when applicable. It supplies no unrelated category. WordPress may then apply its configured default category, as permitted by the existing product architecture.

## AI prompt and response review

AI does not participate in category selection, so no category allowlist or response-schema change was appropriate.

The mandatory response protocol still requests exactly:

- one article title;
- article body;
- `SEO_TITLE`;
- `SEO_DESCRIPTION`;
- `FOCUS_KEYPHRASE`.

Adding AI category selection would have redesigned established prompt and parser behavior unnecessarily. Source-category labels already provide the stable category context needed for exact WordPress resolution.

## Files modified

- `Core.php` — non-instantiated jobs resolver entry in the module autoload map.
- `modules/jobs/JobsArticleCategoryResolver.php` — new deterministic category resolver.
- `modules/jobs/JobsExecutionController.php` — source-context resolution, warning logging, final term validation, and `post_category` insertion.
- `tests/runtime/article-categorisation-regression.php` — self-cleaning WordPress runtime regression suite.
- `docs/modules/jobs-module.md` — generated-post categorisation contract.
- `docs/architecture/prompt-and-author-context.md` — explicit AI/category boundary.
- `docs/references/index.md` — report index entry.
- `docs/references/article-categorisation-audit-and-fix-report.md` — this report.

## Automated tests

Run through WP-CLI:

```bash
wp eval-file tests/runtime/article-categorisation-regression.php
```

The self-cleaning suite passed 16 assertions:

- exact numeric ID preference;
- first top-level category by exact slug;
- another top-level category by normalized exact name;
- child category without parent collapse;
- materially different display name and slug;
- HTML entity normalization;
- category-order independence;
- substring rejection;
- explicit unmatched reporting;
- empty and malformed input rejection;
- explicit empty/malformed reporting;
- execution-path unresolved reporting;
- persistent warning logging;
- post insertion with only the resolved child term;
- preservation of multiple legitimate term IDs;
- invalid-ID behavior using WordPress's configured default rather than the first created fixture.

The suite creates unique terms, posts, and one warning row, then deletes them. It would fail under the previous behavior because the resolver did not exist and the previous `createPost()` method never accepted or assigned category IDs.

## Browser validation

### Deterministic direct cases

Disposable categories were created in wp-admin and posts were inserted through the patched resolver and `createPost()` path. The Posts list showed the expected category for every case:

| Case | Input reference | Expected term ID | Actual term ID | Browser post ID |
| --- | --- | ---: | ---: | ---: |
| First top-level category | `cd3-alpha-first` | 3 | 3 | 65 |
| Another top-level category | `CD3 Editorial Strategy` | 4 | 4 | 66 |
| Child category | `cd3-child-insights` | 6 | 6 | 67 |
| Name containing spaces | `CD3 Markets and Money` | 5 | 5 | 68 |
| Slug materially different from display name | `cd3-materially-different` | 5 | 5 | 69 |
| Explicit resolution failure | `cd3-no-such-category` | 1 (WordPress default) | 1 | 70 |

The child post editor showed `CD3 Child Insights` checked and `CD3 Alpha First`, `CD3 Editorial Strategy`, `CD3 Markets and Money`, and `Uncategorized` unchecked. The post title, body word count, author, draft status, and other editor controls remained intact.

### Full provider-backed job run

A temporary top-level term was created with ID 17 and slug `content-pack-debt-1-50`, matching job 32's real selected library directory. Job 32 then ran once through the complete configured pipeline using `gpt-5-nano`:

| Field | Result |
| --- | --- |
| Job | 32 (`Single > Instant #2`) |
| Sources used | 5 |
| Source categories | 1 |
| Generated post | 74 |
| Post status | Draft |
| Post author | 1 (`admin`) |
| Expected category | ID 17, `Content Pack Debt 1 50` |
| Actual category | ID 17, `Content Pack Debt 1 50` |
| Unresolved categories | None |
| Article integrity | 963 words, 6,611 content bytes |
| Yoast SEO title | `Debt Consolidation: A Practical Guide` |
| Yoast description | Present and valid |
| Yoast focus keyphrase | `debt consolidation guide` |

The post editor showed term 17 checked and `Uncategorized` unchecked. No unrelated term was assigned and no duplicate term was created.

The featured-image attempt reached the existing image path but could not write to `/wp-content/uploads/2026/08/` because of local filesystem permissions. No attachment or partial file was created. This environment issue is unrelated to categorisation; text generation, parsing, post creation, author assignment, status, SEO metadata, and category assignment all completed successfully.

All disposable Browser posts and terms were removed after validation. The five used-source registry rows created by the full run were deleted, job 32's previous last-result post ID 60 was restored, and the category taxonomy returned to its baseline single term ID 1.

## Regression assessment

| Area | Assessment |
| --- | --- |
| Article generation | PASS — full configured AI run completed. |
| Job execution | PASS — job 32 returned success and post 74. |
| AI requests | PASS — existing prompt and provider path were unchanged; `gpt-5-nano` returned content. |
| System prompt overrides | PASS by code isolation — category handling occurs after response parsing and does not alter prompt resolution. |
| Author handling | PASS — generated post retained configured author ID 1. |
| Scheduling | PASS by shared-path review — instant and scheduled entry points still converge on the same `executeJob()` method; scheduler code was untouched. |
| Post creation | PASS — title, 963-word body, draft status, author, and category persisted. |
| Metadata generation | PASS — article parsing and hidden SEO metadata remained intact. |
| SEO fields | PASS — title, description, and focus keyphrase were stored. |
| Existing category configuration | PASS — only actual source references are resolved; no job meta schema changed. |
| Parent/child taxonomy | PASS — child ID 6 remained the assigned term and its parent was not substituted or added. |
| Duplicate categories | PASS — resolver never calls term-creation APIs; term counts were unchanged except for disposable fixtures. |
| Featured images | ENVIRONMENT LIMITATION — the existing local uploads directory rejected the generated file write; no categorisation code contributed to the failure. |

## Conclusion

The bug was the absence of category assignment, which caused WordPress's default category to appear as a first-category bias. The corrected implementation deterministically maps actual source-category context to existing terms, validates IDs at both resolution and insertion boundaries, supports hierarchy and multiple legitimate categories, rejects loose or ambiguous matches, and logs genuine failures without choosing an unrelated term.
