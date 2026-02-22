# Configuration

Core configuration is assembled inside `ExMomentAuthorCoreSystem::__construct()`.

## Important Buckets

- `base`: plugin path and URL.
- `paths.modules`: module root path.
- `autoload`: module-to-controller registration map.
- `moduleConfig`: module runtime settings.
- `resources`: script/style/image URL roots.

## Module Registration Model

`autoload` uses module keys (for example `jobs`, `gpt`, `library`) with controller specs:

- `class`: class or nested class path.
- `instantiate`: whether core should create the instance.

## Configuration Notes

- GPT/API settings are consumed by GPT and settings flows.
- Scheduler and jobs behavior is wired through jobs module controllers.
- Library defaults include optional welcome CTA URL and activation seeding paths.

## Change Guidance

- Keep module key names consistent with folder names.
- Prefer extending `moduleConfig` for module options instead of hardcoding in controllers.
- Update docs when new module keys or controller registrations are added.
