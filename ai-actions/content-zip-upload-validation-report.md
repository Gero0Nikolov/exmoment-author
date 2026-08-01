# CD3 Content ZIP Upload Validation Report

## Final Status

`PASS`

The rejection was reproduced with a real optimized-content ZIP, the exact
validation defect was confirmed, and the smallest coherent plugin-side fix was
applied. The original ZIP and targeted valid layouts now import successfully;
nested, mixed, unsafe, hidden, symbolic-link, and unsupported content remains
rejected.

## Rejection Location and Call Path

The rejection was returned by:

- File: `modules/library/LibraryController.php`
- Method: `LibraryController::inspectArchiveStructure()`
- Pre-fix error code: `exmoau_library_upload_depth`
- Pre-fix message: `Upload rejected: files must live directly inside the category directory.`

The request call path is:

1. `modules/library/views/index.php` renders the hidden
   `library_archive` ZIP input and Upload button.
2. `resources/src/scripts/autoload/admin/library.js`
   `handleUploadChange()` calls `startUpload()`.
3. `startUpload()` checks the client-side `.zip` suffix and 10 MB limit, then
   calls `uploadArchive()`.
4. `uploadArchive()` posts `action=exmoau_library_upload`, the library nonce,
   and `library_archive` to `wp-admin/admin-ajax.php` using same-origin
   credentials.
5. `LibraryController::__construct()` registers
   `wp_ajax_exmoau_library_upload` to `handleUploadLibrary()`.
6. `handleUploadLibrary()` checks the nonce, calls `validateAjaxRequest()` for
   capability/referrer/nonce validation, validates the PHP upload payload,
   upload size, `.zip` suffix, and ZIP readability, then calls
   `inspectArchiveStructure()`.
7. Before the fix, `inspectArchiveStructure()` treated the first path segment
   of every entry as a directory and required every non-directory entry to
   contain exactly two segments. A root entry therefore failed immediately.
8. A validated archive is extracted with WordPress `unzip_file()`, copied into
   `uploads/exmoau-library/<category>`, hardened, and returned to the UI as the
   imported category.

Current reference points after the fix:

- AJAX registration: `modules/library/LibraryController.php:64`
- Upload handler: `modules/library/LibraryController.php:689`
- Authentication/referrer/nonce validation: `modules/library/LibraryController.php:1139`
- Archive validator: `modules/library/LibraryController.php:1789`
- Validated extraction/copy: `modules/library/LibraryController.php:859`
- Job file eligibility: `modules/jobs/JobsExecutionController.php:3166`

## Confirmed Expected Structure

The runtime library structure remains:

```text
uploads/exmoau-library/
└── <category>/
    ├── <article>.txt
    └── <article>.md
```

The upload contract now supports the two structures evidenced by the existing
import architecture and the real content-pack workflow:

1. Root layout: article files are directly at ZIP root. The sanitized ZIP
   filename becomes the category name.
2. Existing directory layout: one top-level category directory contains the
   article files directly.

An archive cannot mix these layouts. Nested directories remain invalid. Only
one category can be imported per ZIP.

## Real Failing ZIP

Read-only archive inspected and uploaded:

```text
/Users/gergannikolov/Desktop/NewBeam/codex-skills/Content-Optimised/50/debtconsolidation/content-pack-debt-consolidation-2-50.zip
```

Archive facts:

- Size: 87,041 bytes
- Entries: 50
- Article files at ZIP root: yes, all 50
- Directory entries: none
- Hidden entries: none
- `__MACOSX` or other metadata entries: none
- Entry type: every entry is `.md`
- First failing entry:
  `confront-your-debts-variant.md`

Raw central-directory entry names from `unzip -Z1`:

```text
confront-your-debts-variant.md
conquer-fiscal-strain-with-low-cost-debt-consolidation-loans-variant.md
consider-a-program-to-consolidate-your-debt-variant.md
consider-debt-consolidation-to-improve-your-credit-ratings-variant.md
consolidate-a-credit-card-to-reduce-your-debt-variant.md
consolidate-and-live-debt-free-variant.md
consolidate-before-it-s-too-late-variant.md
consolidate-credit-card-debt-eliminate-debt-with-a-home-equity-loan-variant.md
consolidate-debt-free-yourself-from-debt-bondage-variant.md
consolidate-debt-into-a-single-payment-variant.md
consolidate-debt-with-home-equity-as-security-variant.md
consolidate-defaulted-student-loans-a-safe-option-variant.md
consolidate-your-government-student-loans-variant.md
consolidating-debt-5-warning-signs-of-a-shady-debt-consolidation-or-debt-management-company-variant.md
consolidating-debt-debt-reduction-without-owning-a-home-variant.md
consolidating-debt-find-the-best-balance-transfer-card-variant.md
consolidating-debt-first-step-towards-a-stress-free-life-variant.md
consolidating-debt-how-to-get-the-lowest-interest-rate-on-a-debt-reduction-or-consolidation-loan-variant.md
consolidating-multiple-loans-variant.md
consolidating-my-debts-affect-my-credit-variant.md
consolidating-student-loans-variant.md
consolidating-your-credit-card-debt-variant.md
consolidating-your-debt-variant.md
consolidation-loan-a-good-way-to-clear-your-debts-variant.md
consolidation-loan-student-programs-bringing-your-dept-under-control-alt-variant.md
consolidation-loan-student-programs-bringing-your-dept-under-control-variant.md
consolidation-loans-for-homeowners-when-multiple-credits-become-a-burden-variant.md
consolidation-service-debt-settlement-variant.md
constantly-planning-to-get-out-of-debt-variant.md
consumer-credit-debt-consolidation-what-are-your-options-variant.md
consumer-debt-consolidation-programs-tips-for-choosing-the-right-program-variant.md
consumer-debt-solution-analyzing-your-options-variant.md
correcting-your-debt-problem-variant.md
could-your-debt-cost-you-your-home-variant.md
credit-and-debt-counselling-in-the-uk-variant.md
credit-bureaus-who-are-they-variant.md
credit-card-applications-getting-approved-after-refusal-variant.md
credit-card-debt-consolidation-best-methods-variant.md
credit-card-debt-consolidation-getting-out-and-staying-out-variant.md
credit-card-debt-consolidation-how-to-get-out-of-your-credit-card-debt-in-an-easiest-way-variant.md
credit-card-debt-consolidation-loans-dig-you-out-of-the-payment-grave-variant.md
credit-card-debt-consolidation-program-variant.md
credit-card-debt-consolidation-programs-a-complete-guide-variant.md
credit-card-debt-consolidation-top-3-factors-to-consider-variant.md
credit-card-debt-consolidation-variant.md
credit-card-debt-consolidation-what-options-are-available-variant.md
credit-card-debt-help-variant.md
credit-card-debt-increasing-every-day-variant.md
credit-card-debt-on-the-rise-variant.md
credit-card-debt-reduction-3-tips-to-quickly-reduce-debts-and-improve-credit-rating-variant.md
```

### Pre-fix Interpretation

For the first entry, the old validator performed the following interpretation:

```text
raw name:       confront-your-debts-variant.md
normalized:     confront-your-debts-variant.md
directory:      false
segments:       [confront-your-debts-variant.md]
segment count:  1
top level:      confront-your-debts-variant.md
```

It then incorrectly added that top-level filename to its directory map. The
next condition required `count($segments) === 2`, so the one-segment root file
returned `exmoau_library_upload_depth`. All remaining raw entries have the same
one-segment root shape, but execution stopped at the first entry.

## Exact Root Cause

The shown rejection had two distinct confirmed causes/blockers:

1. The direct cause of the displayed directory error was the hard-coded
   two-segment requirement in `inspectArchiveStructure()`. Root files were also
   incorrectly recorded as directory names before that check.
2. After correcting the path-depth defect, the same real ZIP would still have
   failed because the optimized-content workflow produces `.md` articles while
   both the library allowlist and job scanner admitted only `.txt` files. Every
   optimized-content ZIP found in that workflow contains `.md` entries.

Both corrections were necessary for the original failing ZIP to import and be
usable by ExMoment Author jobs.

## Minimal Fix Applied

### `modules/library/LibraryController.php`

- Classifies root files and one-directory files separately instead of treating
  every first segment as a directory.
- Uses the sanitized ZIP basename as the category for root-layout archives.
- Preserves the existing single-category-directory layout.
- Rejects mixed layouts and nested directories with specific errors.
- Adds `.md` to the existing `.txt` article allowlist.
- Provides a safe `text/markdown` preview fallback because WordPress does not
  recognize `.md` by default in this installation.
- Rejects NUL, absolute, drive-prefixed, traversal, hidden, and symbolic-link
  entries before extraction.
- Continues ignoring known `__MACOSX`, `.DS_Store`, `Thumbs.db`, and `._*`
  metadata, and passes those validated names to `copy_dir()` so ignored
  metadata is not imported.
- Keeps extraction through `unzip_file()` and copies only from the validated
  root/category location.
- Replaces misleading generic upload errors with errors specific to missing,
  invalid, empty, mixed, nested, or unsupported input.

### `modules/jobs/JobsExecutionController.php`

- Adds `.md` to the job scanner's existing `.txt` source allowlist so imported
  optimized articles are eligible job sources.

### Documentation

- Documents the confirmed upload layouts and allowed types in
  `docs/modules/library-module.md`.
- Adds the operational upload procedure to
  `docs/operations/library-admin-ui.md`.

## Files Changed

- `modules/library/LibraryController.php`
- `modules/jobs/JobsExecutionController.php`
- `docs/modules/library-module.md`
- `docs/operations/library-admin-ui.md`
- `ai-actions/content-zip-upload-validation-report.md`

No content-generation repository files or generated source articles were
modified. No commit was created.

## Security Behavior Preserved

- The upload remains registered only as authenticated `wp_ajax_`; no
  `wp_ajax_nopriv_` upload action was added.
- `manage_options`, same-host referrer, and nonce checks are unchanged.
- PHP upload validation, `is_uploaded_file()`, upload error handling, non-empty
  size validation, the 10 MB limit, `.zip` suffix validation, and ZIP-open
  validation are unchanged.
- Entry paths are normalized but never flattened before validation.
- Nested and mixed layouts are rejected.
- Relative-path containment is strengthened to reject absolute, Windows-drive,
  traversal, NUL, and symbolic-link entries before extraction.
- Unsupported extensions remain rejected before extraction.
- Extraction still uses WordPress `unzip_file()` into a unique temporary
  directory; finalization still uses `copy_dir()` and the existing hardened
  library destination.
- Known system metadata may be ignored, but it is explicitly skipped during
  the final copy and is not imported.
- Existing directory hardening (`index.php` and `.htaccess`) remains active.

## Targeted Validation Results

All UI upload checks used the running local WordPress installation at
`http://localhost:8080` and the plugin's actual Tools → ExMoment Author Library
interface/request handler.

| Case | Result | Evidence |
| --- | --- | --- |
| Original real optimized ZIP before fix | PASS (reproduced) | UI returned the exact reported directory error. |
| Original real optimized ZIP after fix | PASS | UI created `content-pack-debt-consolidation-2-50` and listed all 50 `.md` entries. |
| Root `.txt` ZIP | PASS | `cd3-root-txt.zip` created category `cd3-root-txt` with `one.txt` and `two.txt`. |
| Correct category assignment | PASS | Root layouts used sanitized ZIP basenames; the real archive produced `content-pack-debt-consolidation-2-50`. |
| Existing one-directory layout | PASS | `cd3-category-directory/article.txt` imported into `cd3-category-directory`. |
| Nested directory | PASS | `category/nested/article.txt` was rejected with `nested directories are not allowed`. |
| Mixed root/directory layout | PASS | Root `root.txt` plus `category/article.txt` was rejected with the specific mixed-layout error. |
| Unsafe traversal | PASS | `../escape.txt` was rejected as a non-relative/traversal path. |
| Symbolic-link entry | PASS | A Unix-mode `link.txt` symlink entry was rejected before extraction. |
| Unsupported file type | PASS | Root `article.php` was rejected; only `.txt` and `.md` are accepted. |
| Known metadata | PASS | Root article plus `__MACOSX/._article.txt` and `.DS_Store` imported only `article.txt`; metadata was not copied. |
| Markdown preview | PASS | The imported `.md` article opened in the existing library preview dialog. |
| Job source eligibility | PASS | The job scanner returned 50 candidates from the real imported category; the first candidate extension was `md`. |
| Authentication | PASS | An unauthenticated upload action returned HTTP 400/body `0`; there is no unauthenticated upload hook. |
| Capability/referrer/nonce | PASS | Targeted invocation returned `exmoau_library_forbidden`, `exmoau_library_invalid_referer`, and `exmoau_library_invalid_nonce` for invalid requests. |
| Syntax/format | PASS | PHP lint passed for both changed PHP files; `git diff --check` passed. |

The temporary validation user and the four categories created by this test run
were removed afterward. The pre-existing `finance-chunk-05` category was
confirmed preserved. Source ZIPs and articles remain unchanged.

## Remaining Limitations

- One ZIP imports one category only.
- Root-layout category naming depends on the sanitized ZIP filename.
- A category whose sanitized name already exists is rejected; uploads do not
  merge or overwrite categories.
- Only `.txt` and `.md` article files are supported.
- Nested and mixed archive layouts are intentionally unsupported.
- The existing 10 MB limit remains the uploaded archive-size limit; this task
  did not change upload-size semantics.
