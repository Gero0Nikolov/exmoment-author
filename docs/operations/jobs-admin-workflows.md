# Jobs Admin Workflows

## Scope

Runbook for common wp-admin job operations.

## Workflows

- Create and configure a new job.
- Update schedule rows and execution settings.
- Trigger manual execution and inspect notices.
- Diagnose validation errors blocking publication.
- Confirm repeating jobs continue to advance next-run metadata.

## Prompt and author-context checks

1. Leave **Custom System Prompt** blank and confirm the job inherits the effective AI Setup editorial prompt.
2. Save a multiline custom prompt and confirm it persists across editor reload and job-type changes.
3. Confirm a valid custom prompt changes only editorial guidance; the generated response still follows the mandatory title/body and hidden SEO metadata protocol.
4. Submit an oversized value only in a disposable test and confirm the previous valid prompt remains stored and a validation notice appears.
5. Test author context disabled and enabled. When enabled, use a disposable author with a known public display name and confirm the generated post author remains the same effective user.
6. Restore all temporary settings and job metadata.

Publish/manual Instant execution and both scheduled job types share `JobsExecutionController::executeJob()`. Validate each entry point when release scope changes shared prompt or author resolution.

## Job Setup tile checks

Use one short directory label and one long or unbroken label:

- long visible text remains on one line, truncates with ellipsis, and stays within the button;
- the button's native `title` equals the complete original label;
- short text remains fully visible;
- selected and unselected tiles have the same geometry and preserve WordPress button padding;
- click and Enter/Space activation preserve `aria-pressed`, primary/secondary classes, stored directory value, and save persistence;
- Mixture AJAX refreshes render the same markup and behavior as initial page load.

The presentation is scoped to `.exmoau-job-setup__tile`; it must not change unrelated WordPress buttons.

See [Testing Strategy](testing-strategy.md) and [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md).
