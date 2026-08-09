# Version 1.3.5 Yoast SEO Title Template Preparation

## Final status

**PASS** — ExMoment Author `1.3.5` now has authoritative Browser evidence from the real job publish lifecycle. Job `83` was saved as Draft and published again through the authenticated WordPress editor. That publish transition executed the production `save_post_exmoau_job` hook and created published post `113`, whose stored Yoast title, Yoast indexable title, editor field, and rendered preview all contain or resolve the canonical separator and site-name variables correctly.

No Git commit was created. WordPress.org SVN was not accessed, synchronized, modified, tagged, committed, or deployed.

## Why the previous PASS was insufficient

The initial `1.3.5` validation proved the template composer, direct integration write, dynamic Yoast replacements, and a provider-backed controller execution. Its temporary generated draft was deleted after validation. It did not exercise the exact requested job-editor Draft-to-Publish interaction, and it left no current generated post for manual inspection.

Post `101` remained the newest persistent post from job `83`, so manual inspection correctly found its historical raw Yoast title and appeared to contradict the earlier result. The new investigation preserved post `101`, traced the production path, and then exercised that exact job through Browser.

## Post 101 failing-state evidence

Post `101` was inspected before any change.

- Post title: `Emergency Finances: Preparing for the Unexpected`
- Status: Published
- Yoast SEO title field: `Emergency Finances: Preparing for the Unexpected`
- Yoast Google preview: `Emergency Finances: Preparing for the Unexpected`
- Separator present: no
- Site title present: no
- Stored `_yoast_wpseo_title`: `Emergency Finances: Preparing for the Unexpected`
- Yoast indexable title: `Emergency Finances: Preparing for the Unexpected`
- Yoast indexable ID/version: `126` / `2`

The stored post meta and indexable title match. This is not a stale-preview or stale-indexable problem. Post `101` is historical data created before the canonical variable composer was present in the local runtime.

Post `101` was deliberately left unchanged. The task does not bulk-rewrite historical SEO titles, and newly generated posts are the authoritative release criterion.

## Complete write-path audit

There is one ExMoment Author writer for `_yoast_wpseo_title`:

```text
JobsExecutionController::executeJob()
→ parseArticleResponse()
→ validated seo_meta['seo_title']
→ createPost()
→ maybePopulateYoastSeoMeta()
→ YoastSeoIntegration::maybeUpdatePostSeo()
→ YoastSeoIntegration::composeYoastTitleTemplate()
→ update_post_meta(_yoast_wpseo_title)
```

The generation paths converge before persistence:

- publish-triggered and manual Instant jobs call `runJobNow()`;
- scheduled jobs call `runScheduledJob()`;
- both call `runJobGenerations()` and the same `executeJob()` method;
- only `YoastSeoIntegration::maybeUpdatePostSeo()` writes the Yoast title meta.

No second ExMoment Author writer, legacy raw-title persistence path, retry writer, scheduler-specific title writer, or post-finalization title writer was found.

The job save order is:

- `JobsMetaController::saveJobMeta()` at priority `10`;
- `JobsSchedulingController::syncJobSchedule()` at priority `20`;
- `JobsExecutionController::maybeRunJobOnSave()` at priority `40`.

The generated post is inserted first, and canonical SEO metadata is written immediately afterward. A normal Browser save of generated post `113` preserved exactly one `%%sep%%` and one `%%sitename%%`. There is no observed later overwrite race.

## Actual root cause

The original defect was the pre-`1.3.5` persistence behavior: the integration wrote the validated AI title directly to `_yoast_wpseo_title`. The existing `1.3.5` runtime patch corrects that writer by composing the canonical Yoast template before persistence.

The apparent continued failure was caused by inspecting historical post `101`, which predates that corrected writer, after the earlier validation post had been cleaned up. The production job path does not bypass `YoastSeoIntegration`, and no additional competing write path was found.

## Yoast implementation inspected

The local site runs Yoast SEO `27.2`. Inspection of the installed plugin confirmed:

- `_yoast_wpseo_title` is the post-level custom title key;
- `%%sep%%` is the canonical separator variable;
- `%%sitename%%` is the canonical site-name variable;
- Yoast's default post template uses `%%title%% %%page%% %%sep%% %%sitename%%`;
- `wpseo_replace_vars()` is the installed replacement helper;
- the separator key is stored in `wpseo_titles.separator` and resolved through Yoast's separator map;
- `%%sitename%%` resolves from the current site name through Yoast's site helper.

The implementation stores variables, not rendered punctuation or branding.

## Corrected persistence behavior

`YoastSeoIntegration::composeYoastTitleTemplate()` removes only exact existing `%%sep%%` and `%%sitename%%` tokens from the validated title text and appends one canonical suffix:

```text
<article-specific title> %%sep%% %%sitename%%
```

Literal punctuation and possible literal site-name text are preserved because fuzzy stripping could corrupt legitimate article titles. Empty and invalid titles retain the existing no-write behavior. Missing or inactive Yoast returns safely.

## Authoritative post 83 Browser execution

The authenticated WordPress workflow was:

```text
job 83 Published
→ Edit status
→ Draft
→ Update
→ confirmed “Post draft updated” and Status: Draft
→ Publish
→ production save hook executes
→ “Created post #113 (publish)”
```

The job notice reported five source articles from one category, model `gpt-5-nano`, and a 35.98-second generation duration. Job `83` returned to Published status with `exmoau_job_last_result_post_id = 113` and successful execution status.

## Generated post 113 evidence

- Generated article title: `Fintech Tools: Payday Advances Emini Futures Email Security`
- Validated AI SEO title passed into the canonical title path: `Fintech Tools: Payday Advances Emini Futures Email Security`
- Composed template: `Fintech Tools: Payday Advances Emini Futures Email Security %%sep%% %%sitename%%`
- Final stored `_yoast_wpseo_title`: `Fintech Tools: Payday Advances Emini Futures Email Security %%sep%% %%sitename%%`
- Yoast indexable title: `Fintech Tools: Payday Advances Emini Futures Email Security %%sep%% %%sitename%%`
- Configured separator: `sc-dash` (`-`)
- Configured site title: `ExMoment Author`
- Full rendered title through `wpseo_replace_vars()`: `Fintech Tools: Payday Advances Emini Futures Email Security - ExMoment Author`
- Post status: Published
- Author: `Jasmine Rodrigez`

The Browser Yoast Search appearance panel showed the generated article-specific title followed by separate `Separator` and `Site title` components. Its Google preview visibly rendered the dash and truncated `ExMoment Author` at the preview width. The editor's full resolved value was `Fintech Tools: Payday Advances Emini Futures Email Security - ExMoment Author`.

A subsequent normal Browser Save produced “Post updated.” The stored meta and Yoast indexable row remained canonical, each variable appeared exactly once, and the full rendered result was unchanged.

## Indexable and cache finding

Yoast's indexable layer mirrors the stored template in both inspected cases:

- historical post `101`: raw title in post meta and indexable title;
- current post `113`: canonical variable template in post meta and indexable title.

The Browser preview agrees with each representation. No supported indexable rebuild is required, and no Yoast internal table was modified directly.

## Regression coverage

The WordPress runtime suite now covers both the composer and the actual job metadata persistence method:

- plain generated title composition;
- neither, either, or both canonical variables;
- exact one-variable counts;
- empty and malformed values;
- preservation of literal punctuation;
- active Yoast detection;
- the `save_post_exmoau_job` execution hook at priority `40`;
- raw job SEO metadata entering `JobsExecutionController::maybePopulateYoastSeoMeta()`;
- final persisted canonical `_yoast_wpseo_title`;
- normal post update after persistence;
- draft-to-publish preservation;
- no later raw-title overwrite;
- repeated saves without suffix duplication;
- existing canonical and maximum-length title preservation;
- unavailable Yoast no-write/no-fatal behavior;
- dynamic site-name replacement;
- temporary separator-change replacement with an unchanged stored template.

The suite restores temporary options and deletes its post fixture.

## Complete local validation

- Yoast SEO title-template and real persistence regression: 24/24 assertions passed.
- Article categorisation regression: 47/47 assertions passed; fixtures self-cleaned.
- Featured-image prompt regression: 8/8 assertions passed.
- First-party PHP lint: 36/36 files passed. PHP also emitted the pre-existing PHP 8.4 nullable-parameter deprecation in `JobsMetaController`; it is unrelated to this focused change and did not affect syntax validation.
- Composer metadata: valid.
- Documentation links: 52 Markdown files checked with no missing local targets.
- Whitespace and diff validation: passed.
- Local WordPress plugin metadata: active version `1.3.5`.

## Version and changelog

The authoritative release locations remain `1.3.5`:

- plugin `Version` header in `exmoment-author.php`;
- production `resourceVersion` in `Core.php`;
- `Stable tag` in `readme.txt`.

The `1.3.5` changelog remains accurate: the AI supplies article-specific title text, ExMoment Author composes Yoast-native variables once, and Yoast dynamically controls the site-level suffix.

## Cleanup and retained evidence

- Post `101`: retained unchanged as historical failing-state evidence.
- Post `113`: retained as the authoritative successful generated-post evidence requested by this CD3.
- Job `83`: ends Published, as required, with its successful result back-reference to post `113`.
- Temporary regression fixtures and temporary separator/site-title settings: restored or removed by the regression suite.
- Temporary diagnostics: none added.
- SVN: not accessed or modified.
