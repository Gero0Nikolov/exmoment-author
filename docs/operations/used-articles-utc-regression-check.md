# Used Articles UTC Regression Check

## Goal

Confirm used-articles timestamps and schedule-related comparisons remain UTC-safe.

## Regression Steps

1. Generate entries across multiple local timezones/environments.
2. Compare persisted timestamps and scheduler calculations.
3. Validate ordering/selection logic is consistent.
4. Audit logs for timezone conversion issues.
