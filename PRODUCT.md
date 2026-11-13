# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- Primary: a corporation logistics officer at their PC, often beside the EVE client, checking doctrine ships stored in the corp hangar before an op or while restocking.
- Access is granted per corporation through SeAT roles (role filters per corporation); CEOs and directors pass automatically. One permission views, another manages fits.

## Product Purpose

`seat-custom-plugin` is the NIS alliance's own SeAT 5 plugin. This feature checks every assembled ship in a corporation's corp hangar at a chosen staging against the registered doctrine fit that carries exactly the ship's in-game name (and hull), and shows which ships are wrong (missing or extra modules per slot, missing cargo, container contents, fuel/fleet bay quantities), and per fit how many ships are ready out of the target, how many need fixing, and how many are still missing (to buy, or to rename after the fit). Ships not named after any fit are listed separately, with the exact fit name to rename to when they differ only by case or spacing.

Success: the officer opens the page and immediately sees which hangar ships need fixing and what exactly to change, then sees per-fit shortfall.

## Positioning

Unlike generic SeAT fitting plugins (which check pilot skills against fits), this checks the physical ships in the corp hangar, using SeAT's already-synced corporation assets and SDE dogma data, with no extra ESI tokens.

## Operating Context

- Runs inside SeAT 5 (Laravel, AdminLTE/Bootstrap 4 theme, Blade views) at seat.nisuwaz.com, deployed via `SEAT_PLUGINS` in the Portainer stack.
- Fits are managed (add, edit, delete) on SeAT itself, per corporation. Stagings are chosen on the page. Targets are per fit and staging.
- Ships of interest include capitals with variant loadouts (e.g. Zirnitra fits that differ only by container/bay contents) and abyssal modules.

## Capabilities and Constraints

- UI copy: English only.
- Corp hangar items sit under the corporation office (`OfficeFolder`) at the staging, flags `CorpSAG1..7`.
- Modules with identical group and dogma attributes count as equivalent.
- Deleting a fit is a hard delete; created/updated-by is recorded.
- The separate eve_helper tool stays as is; no data is migrated from it.

## Evidence on Hand

- Real doctrine examples: Zirnitra variants and an Archon fit (from the owner's eve_helper). No hangar data has been captured yet; mock data must be labeled as such.

## Product Principles

1. Problems first: the ship that needs fixing outranks the healthy count.
2. Say exactly what to change: module names, slot, and quantity, not just a status.
3. Look native to SeAT: an officer should not notice where SeAT ends and the plugin begins.
4. Corporation boundaries are absolute: never show or edit another corporation's data.
