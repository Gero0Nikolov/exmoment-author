# Core Runtime

`Core.php` defines `ExMomentAuthor\Core\ExMomentAuthorCoreSystem`, the central runtime loader.

## Responsibilities

- Registers activation/deactivation hooks.
- Registers module class autoloader.
- Builds runtime config for modules/resources.
- Autoloads module controllers declared in `config['autoload']`.
- Keeps minutely scheduler event present.
- Loads admin/login assets.
- Exposes controller lookup through `getModule()`.

## Lifecycle

1. `exmoment-author.php` requires `Core.php`.
2. Core instance initializes config and hooks.
3. `init` executes `autoload()` and module constructors.
4. Runtime hooks continue through admin and front-end lifecycle.

## Activation and Deactivation

- Activation seeds bundled library archives and scheduler state.
- Deactivation calls scheduler cleanup.

## Key Source Files

- `exmoment-author.php`
- `Core.php`
- `modules/jobs/JobsSchedulerWorker.php`
- `modules/library/UsedArticlesRepository.php`
