# Cache Module

## Scope

Cache module handles post-save recache behavior.

## Components

- `modules/cache/FlygRecacheService.php`
- `modules/cache/SavePostFlygRecache.php`

## Responsibilities

- Hook save events and trigger recache routines.
- Keep cache refresh concerns separate from content-generation logic.
