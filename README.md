# seat-custom-plugin

The NIS alliance's own plugin for [SeAT 5](https://github.com/eveseat/seat).

## Features

### Hangar Check

Checks every assembled ship in a corporation's corp hangar at a chosen staging against the doctrine fit that carries exactly the ship's in-game name (and hull). For each ship it shows what to add or remove per slot, and what is short in cargo, containers and fuel or fleet bays. Per fit it shows how many ships are ready out of the number required at that staging, how many need fixing and how many are still missing. Ships not named after any fit are listed separately, with the exact fit name to rename to when they differ only by case or spacing.

A refit module that the fit carries in cargo counts as on board when it is fitted, and a fit module carried in cargo counts as fitted. Rigs are never swapped.

### Doctrine Fits

Add, edit and delete doctrine fits (EFT plus bay and container contents), stagings and, per staging, how many ships of each fit are required.

### Squad and role sync

`php artisan custom-plugin:sync-squads-with-roles [--dry-run]` gives every user the roles mapped to the squads they belong to. Configure it on the Settings page:

- **Squad ID ↔ Role ID map**: JSON object of SeAT squad IDs to SeAT role IDs.
- **Inactive role ID**: users holding this role keep it and lose every other role that is not on the ignore list.
- **Ignore role IDs**: roles the sync never adds or removes.

The sync removes every role that is not mapped or ignored, so add manually granted roles (for example administrators) to the ignore list. Run with `--dry-run` first. The plugin does not schedule the command.

### Sign in with SeAT for the intel archive

The intel archive (intel.nisuwaz.com) signs its users in with their SeAT account through OAuth 2.0 (authorization code grant with PKCE, S256 only). There is one registered application, no consent screen and no refresh token:

- `GET /custom-plugin/oauth/authorize`: a signed-in user with the **Intel Archive** permission is sent straight back with a one-time code (valid 60 seconds). Without the permission, without a main character, or when the main character has since been linked into another SeAT account, the user is sent back with `error=access_denied`. An unknown `client_id` or a `redirect_uri` that is not byte for byte the registered one gets an error page and no redirect.
- `POST /custom-plugin/oauth/token`: exchanges the code, the PKCE verifier and the client secret (form body only) for an access token valid 5 minutes. Presenting a code a second time fails and revokes the token issued for it.
- `GET /custom-plugin/oauth/userinfo` with `Authorization: Bearer`: returns `sub` (the SeAT user id as a string, stable across main character changes), `character_id` and `name` of the main character. The permission, and that the user still owns the main character, are checked again on every call.

Token and userinfo answer `429` with `Retry-After` when a budget is used up. Requests without a valid client secret or bearer token count per address (30 and 60 a minute); requests with one count per client (token, 120 a minute) or per user (userinfo, 60 a minute), so callers who share an address behind a proxy cannot lock the archive out.

Codes and tokens live only in the SeAT cache (Redis), keyed by their SHA-256. Nothing is written to the database. The feature is off (404) until all three values are set in the SeAT environment:

```
CUSTOM_PLUGIN_OAUTH_INTEL_CLIENT_ID=intel-archive
CUSTOM_PLUGIN_OAUTH_INTEL_CLIENT_SECRET=<long random value, e.g. openssl rand -base64 48>
CUSTOM_PLUGIN_OAUTH_INTEL_REDIRECT_URI=https://intel.nisuwaz.com/auth/callback
```

The redirect address must be https (http only for localhost) and the secret at least 32 characters. The client authenticates with `client_id` and `client_secret` in the form body (`application/x-www-form-urlencoded`); HTTP Basic and JSON bodies are not accepted. A code is single-use only on a cache store with an atomic `add` (Redis, as SeAT ships; not the file store).

Known differences from RFC 6749: the token endpoint takes `client_secret` only in the form body (no HTTP Basic, so a `401 invalid_client` carries no `WWW-Authenticate`), and an authorize request whose `response_type` is not `code` is sent back with `error=invalid_request` rather than `unsupported_response_type`.

## Installation

Add `goemktg/seat-custom-plugin` to `SEAT_PLUGINS` in the SeAT environment and restart the SeAT containers. Migrations run on start.

## Permissions

- **Hangar Check and Doctrine Fits** (`corporation.doctrine_hangar_view`, per corporation): view Hangar Check and manage the corporation's fits, stagings and required ship counts. Grant it through a SeAT role with a corporation filter.
- **Intel Archive** (`global.intel_archive_view`, global): sign in to the intel archive with SeAT. Superusers always pass.
- **Settings**: superusers only.

## Development

Unit tests cover the framework-free code in `src/Conformance` and `src/Support` (including the OAuth server in `src/Support/OAuth`) and need no SeAT install:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php phpunit.phar
```

`phpunit.phar` is PHPUnit 10.5, downloaded separately.

The OAuth endpoints also have a smoke check that boots the real SeAT app (array cache, in-memory sqlite, no network) and drives authorize, token and userinfo through the HTTP kernel:

```sh
docker run --rm --network none -v "$PWD":/plugin:ro --entrypoint php ghcr.io/eveseat/seat:5.0.110 /plugin/tests/Integration/oauth-seat-smoke.php
```
