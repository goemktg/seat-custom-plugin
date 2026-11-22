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

### Sign in with SeAT for your own applications

Your own applications (the intel archive, SRP and so on) can sign their users in with their SeAT account through OAuth 2.0: the authorization code grant with PKCE (S256 only). There is no consent screen and no refresh token.

- `GET /custom-plugin/oauth/authorize`: a signed-in SeAT user is sent straight back with a one-time code, valid for 60 seconds. The user needs an active account and a main character that is still linked to that account. Otherwise, or when the application's `allowed_role_ids` refuse the user (see below), the user is sent back with `error=access_denied`. An unknown `client_id`, or a `redirect_uri` that is not byte for byte one of that application's registered addresses, gets an error page and no redirect. A `response_type` other than `code` is sent back with `error=unsupported_response_type`.
- `POST /custom-plugin/oauth/token`: exchanges the code and the PKCE verifier for an access token valid for 5 minutes. Only the application the code was issued to can exchange it, and the `redirect_uri` must be the one the code was sent to. Presenting a code a second time fails and revokes the token issued for it.
- `GET /custom-plugin/oauth/userinfo` with `Authorization: Bearer`: returns the user. The account, the main character and `allowed_role_ids` are checked again on every call (`403 access_denied` once they no longer pass).

```json
{"sub": "12", "character_id": 90000001, "name": "Test Pilot", "admin": false,
 "roles": [{"id": 3, "title": "Member"}, {"id": 7, "title": "SRP Officer"}]}
```

`sub` is the SeAT user id as a string, and stays the same when the main character changes; identify accounts by it. `roles` are the user's SeAT roles in id order, including roles given through squads (SeAT copies those onto the user). `admin` is true for a SeAT administrator, who passes every SeAT permission check whatever their roles. Each application decides from these what the user may do there. Every registered application sees all of the user's roles and their titles, so register only applications you trust with that list.

The token endpoint accepts either form of client authentication from RFC 6749 section 2.3.1: HTTP Basic (`client_secret_basic`, with `client_id` and `client_secret` each form-encoded before they are joined), or `client_id` and `client_secret` in the form body (`client_secret_post`, `application/x-www-form-urlencoded`). Sending both is `400 invalid_request`. A failed HTTP Basic attempt gets `401 invalid_client` with `WWW-Authenticate: Basic realm="SeAT"`, and a failed form attempt gets `401 invalid_client`. JSON bodies are refused.

Authorize allows 30 requests a minute for each application and user, so a page that keeps sending the browser to one application cannot use up the user's sign-in to the others. Token and userinfo answer `429` with `Retry-After` when a budget is used up. Requests without valid client credentials or a valid bearer token count per address: 30 a minute for token and 60 for userinfo. Requests with them count per application: 120 a minute for token, and 60 a minute for userinfo for each user. Callers that share an address behind a proxy therefore cannot lock an application out, and one application cannot use up another's budget.

Codes and tokens live only in the SeAT cache (Redis), keyed by their SHA-256. Nothing is written to the database. A code is single-use only on a cache store with an atomic `add` (Redis, as SeAT ships, but not the file store). There are no scopes (`scope` is ignored), no refresh tokens and no OpenID Connect ID token.

#### Registering applications

The applications are listed in one JSON file, named by `CUSTOM_PLUGIN_OAUTH_CLIENTS_FILE` in the SeAT environment. The file holds only the SHA-256 of each secret, never the secret itself:

```json
{"clients": [
  {"client_id": "intel-archive", "name": "Intel Archive",
   "secret_sha256": "<64 lower-case hex characters>",
   "redirect_uris": ["https://intel.nisuwaz.com/auth/callback"]},
  {"client_id": "srp", "name": "SRP",
   "secret_sha256": "<64 lower-case hex characters>",
   "redirect_uris": ["https://srp.nisuwaz.com/auth/callback"],
   "allowed_role_ids": [7]}
]}
```

Make a secret and its hash like this. The secret uses only URL-safe characters, so it needs no encoding in HTTP Basic. Give the secret to the application and put only the hash in the file:

```
SECRET=$(openssl rand -base64 48 | tr '+/' '-_')
printf %s "$SECRET" | sha256sum | cut -d' ' -f1
```

The plugin cannot check how strong a secret is, because the file holds only its hash. Use the command above, not a password someone chose.

Rules for the file. If the file breaks any of them, the whole feature is off and every OAuth address answers 404. Each such request also writes a warning to the SeAT log, with the file path and the kind of problem (for example `client 1 is invalid`, counting from 0), but never a value from the file:

- The file must exist and be readable, be at most 64 KiB of valid JSON, and have exactly one key, `clients`, holding a non-empty list.
- Each application has exactly the keys `client_id`, `name`, `secret_sha256` and `redirect_uris`, plus the optional `allowed_role_ids`. An unknown key, for example a misspelt `allowed_roles`, is an error, so a restriction is never dropped silently.
- `client_id` is 1 to 64 characters of `A-Z a-z 0-9 . _ -`, and is unique in the file. `name` is not empty. `secret_sha256` is 64 lower-case hex characters.
- `redirect_uris` is a non-empty list of distinct addresses. Each one is https (http only for `localhost` and `127.0.0.1`), with a host, no fragment (`#`) and no user information (`user:pass@`). A request must use one of them exactly.
- `allowed_role_ids`, when given, is a list of positive SeAT role ids (`null` is an error, not "no restriction").
- A key repeated inside one object, anywhere in the file, is an error. (JSON readers keep only the last value, which could silently drop a restriction.)

`allowed_role_ids` is optional. Usually leave it out, and let the application decide from `roles` in userinfo. Fill it in only for an application that must keep out everyone without one of those roles, for example to stop outsiders who merely have a SeAT account. With it, a user needs at least one of the listed roles, or must be a SeAT administrator: otherwise authorize sends them back with `access_denied` and userinfo answers `403`. An empty list or a missing key means no restriction.

The file is read and checked again on every request to an OAuth address. A changed file therefore takes effect on the next request without a restart, and removing an application ends its outstanding tokens at once. When the file is a Docker Swarm config or secret, it cannot be changed in place. Create a new config or secret and point the service at it (change the stack file and redeploy, or `docker service update --config-rm <old> --config-add source=<new>,target=<path>`); Swarm then replaces the SeAT containers. For example, with the secret mounted as `/run/secrets/seat_oauth_clients`:

```
CUSTOM_PLUGIN_OAUTH_CLIENTS_FILE=/run/secrets/seat_oauth_clients
```

### Discord link lookup API

`GET /api/v2/custom-plugin/discord-links?discord_id=<Discord user ID>` answers which SeAT account a Discord user is linked to through seat-discord-connector, for bots that hold a SeAT API token. It sits behind SeAT's own API middleware: send the token in `X-Token` (its allowed source address applies) and accept JSON (or send no `Accept` header).

- `200 {"data":[{"user_id":123}]}`, or `{"data":[]}` when the Discord user is not linked. Only the account id is returned.
- `422 {"message":"discord_id must be 1 to 20 digits"}` for a missing or malformed `discord_id`.
- `503 {"message":"seat-connector is not installed"}` when the seat-connector link table does not exist.
- `401` / `406` come from SeAT's API middleware (bad token or address, unsupported `Accept`).
- Any SeAT API token can call it: SeAT tokens have no scopes (and already reach SeAT's own user API), so keep the bot's token as safe as a superuser password.

It reads `seat_connector_users` directly because seat-connector 3.1.2's own `/api/v2/seat-connector/users` returns every field as null. Nothing is written.

## Installation

Add `goemktg/seat-custom-plugin` to `SEAT_PLUGINS` in the SeAT environment and restart the SeAT containers. Migrations run on start.

## Permissions

- **Hangar Check and Doctrine Fits** (`corporation.doctrine_hangar_view`, per corporation): view Hangar Check and manage the corporation's fits, stagings and required ship counts. Grant it through a SeAT role with a corporation filter.
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

The Discord link lookup API has the same kind of smoke check:

```sh
docker run --rm --network none -v "$PWD":/plugin:ro --entrypoint php ghcr.io/eveseat/seat:5.0.110 /plugin/tests/Integration/discord-links-seat-smoke.php
```
