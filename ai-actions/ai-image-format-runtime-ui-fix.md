# AI Image Format Runtime UI Fix

Date: 2026-08-16

## Diagnosis

The `exmoau_ai_image_format` setting, its WebP default, sanitizer, allowlist, and runtime request mapping were already present in the bind-mounted ExMoment Author source. The missing field was a render-placement defect: the control had been added to `modules/settings/views/partials/ai-setup.php`, while the supplied settings URL opens the **AI Client** tab by default. The AI Client partial owns the featured-image controls, including Image style prompt, Image dimensions, provider, and Image model.

The signed-in local WordPress admin confirmed the mismatch before the correction: the field was absent from the default AI Client view and appeared only after selecting the separate AI Setup tab.

## Runtime identity and mount

- Docker Compose service: `wordpress`
- Container: `cooliouncle_wordpress`
- Host repository: `/Users/gergannikolov/Desktop/NewBeam/exmoment-author`
- Container plugin path: `/var/www/html/wp-content/plugins/exmoment-author`
- Active plugin entry: `exmoment-author/exmoment-author.php`
- Loaded plugin version: `1.3.6`

Docker inspection reported a writable bind mount from the host repository to the container plugin path. Host and container SHA-256 hashes matched for the inspected plugin files. WordPress reflection resolved the loaded settings controller to that same container path, and no second ExMoment Author plugin directory or must-use loader was found.

## Correction

The single **AI Image Format** row was moved from `ai-setup.php` to `ai-client.php`, immediately after Image dimensions. No setting registration, sanitizer, provider request, image persistence, theme, Docker, or production configuration was changed.

No container or PHP restart was needed because the running WordPress service was already reading the current bind-mounted PHP source.

## Validation

The in-app Browser verified the default settings URL after the correction:

- the AI Image Format field is visible in the AI Client featured-image area;
- it follows Image dimensions and precedes the remaining provider/model controls;
- the choices are JPEG, WebP, and PNG;
- exactly one AI Image Format control is rendered;
- JPEG persisted after **Update** and a full page reload;
- the saved value reached the image-generation settings as `jpeg` and normalized to `image/jpeg` without calling a provider;
- the original database state was restored by removing the temporary option, after which a Browser reload again showed the WebP default.

The focused runtime regression covers WebP default behavior, all three allowed values, invalid-value preservation, MIME request mapping, returned-byte validation, attachment MIME/extension mapping, and featured-image assignment. It uses local fixtures only and does not make a paid image-provider request.

## Scope confirmation

No ExMoment Social code, production configuration, remote system, commit, push, or deployment was touched. No credentials or secret values were logged or added.
