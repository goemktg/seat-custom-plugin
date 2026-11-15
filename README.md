# seat-custom-plugin

The NIS alliance's own plugin for [SeAT 5](https://github.com/eveseat/seat).

## Features

### Hangar Check

Checks every assembled ship in a corporation's corp hangar at a chosen staging against the doctrine fit that carries exactly the ship's in-game name (and hull). For each ship it shows what to add or remove per slot, and what is short in cargo, containers and fuel or fleet bays. Per fit it shows how many ships are ready out of the target, how many need fixing and how many are still missing. Ships not named after any fit are listed separately, with the exact fit name to rename to when they differ only by case or spacing.

A refit module that the fit carries in cargo counts as on board when it is fitted, and a fit module carried in cargo counts as fitted. Rigs are never swapped.

### Doctrine Fits

Add, edit and delete doctrine fits (EFT plus bay and container contents), stagings and per-staging targets for a corporation.

### Squad and role sync

`php artisan custom-plugin:sync-squads-with-roles [--dry-run]` gives every user the roles mapped to the squads they belong to. Configure it on the Settings page:

- **Squad ID ↔ Role ID map**: JSON object of SeAT squad IDs to SeAT role IDs.
- **Inactive role ID**: users holding this role keep it and lose every other role that is not on the ignore list.
- **Ignore role IDs**: roles the sync never adds or removes.

The sync removes every role that is not mapped or ignored, so add manually granted roles (for example administrators) to the ignore list. Run with `--dry-run` first. The plugin does not schedule the command.

## Installation

Add `goemktg/seat-custom-plugin` to `SEAT_PLUGINS` in the SeAT environment and restart the SeAT containers. Migrations run on start.

## Permissions

- **Hangar Check and Doctrine Fits** (`corporation.doctrine_hangar_view`, per corporation): view Hangar Check and manage the corporation's fits, stagings and targets. Grant it through a SeAT role with a corporation filter.
- **Settings**: superusers only.

## Development

Unit tests cover the framework-free code in `src/Conformance` and `src/Support` and need no SeAT install:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php phpunit.phar
```

`phpunit.phar` is PHPUnit 10.5, downloaded separately.
