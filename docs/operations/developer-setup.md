# Local WordPress via Docker

This repository can be mounted directly as a plugin into a default WordPress container.

## Prerequisites

- Docker Desktop (macOS)
- Project opened at repository root (`exmoment-author`)

## Configure plugin slug

1. Copy `.env.example` to `.env`.
2. Set `PLUGIN_SLUG` to the folder name WordPress should use under `wp-content/plugins/`.

Example:

```dotenv
PLUGIN_SLUG=exmoment-author
```

If your plugin code is not at repo root (for example in `./plugin/`), update the bind mount source in `docker-compose.yml` from `./` to `./plugin/`.

## Start

```bash
docker compose up -d
```

WordPress will be available at [http://localhost:8080](http://localhost:8080).

## Stop

```bash
docker compose down
```

## Reset everything

```bash
docker compose down -v
```

This removes WordPress and database persisted data.

## Install WordPress

1. Open [http://localhost:8080](http://localhost:8080).
2. Complete the default WordPress installer.
3. Sign in to WP Admin.

## Activate plugin

1. Go to `Plugins` -> `Installed Plugins`.
2. Find the plugin mounted from this repo (`PLUGIN_SLUG`).
3. Click `Activate`.

Because this repo is bind-mounted, code edits in this repo are reflected immediately in the container.

## WP-CLI examples

Use the long-running `wp_cli` container:

```bash
docker exec -it wp_cli wp plugin list --allow-root
```

```bash
docker exec -it wp_cli wp plugin activate <plugin-slug> --allow-root
```

```bash
docker exec -it wp_cli wp option get siteurl --allow-root
```

Check PHP version used by WordPress app container:

```bash
docker exec -it wp_app php -v
```

## Run Composer under PHP 8.4

```bash
docker compose up -d
```

```bash
docker compose exec composer php -v
```

```bash
docker compose exec composer composer install
```

## Verify WordPress PHP version

```bash
docker compose exec wordpress php -v
```
