<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/**
 * Decides, fit by fit, what following an upstream doctrine means for the
 * corporation's fits. Reads nothing and writes nothing itself.
 *
 * A linked fit is the one of this source with the upstream fit's hull and
 * name. Upstream fits carry no id, so a renamed fit is a missing one plus a
 * new one.
 *
 * A shared fit of the doctrine site can sit in several followed doctrines, but
 * the corporation keeps one row per hull and name. The doctrine that brought it
 * in first keeps it up to date; the others report it as shared. When that
 * doctrine is no longer followed or no longer has the fit, the next of the
 * others to sync takes the row over and keeps it.
 */
final class SyncPlanner
{
    private const EDITED_HERE = 'The EFT was changed here since the last sync.';

    /**
     * @param  int|null  $sourceId  the followed doctrine's row, null before it is first followed
     * @param  list<array{id: int, label: string, missing: bool}>  $linked  every fit of this source
     * @param  callable(UpstreamFit): array{shipTypeId: ?int, unresolved: list<string>}  $resolve  against SeAT's item data
     * @param  callable(int, string, string): ?array{id: int, sourceId: ?int, eft: string, eftHash: ?string, missing: bool, sameHull: bool, sharedId: ?int, ownerFollowing: bool, ownerName: string}  $find
     *         the corporation's fit that the unique keys would pair with (hull, fit name, doctrine name), as the database compares names;
     *         sharedId, ownerFollowing and ownerName describe the followed doctrine it came from
     * @param  list<int>  $linkIds  manual fits the user chose to hand over to this doctrine
     * @return list<SyncAction>
     */
    public static function plan(UpstreamDoctrine $doctrine, ?int $sourceId, array $linked, callable $resolve, callable $find, array $linkIds = []): array
    {
        $actions = [];
        $seen = [];
        $claimed = [];
        // an upstream fit that cannot be matched to a local one might be any linked fit,
        // so no linked fit is called gone until the doctrine reads cleanly again
        $uncertain = $doctrine->unreadable !== [];

        foreach ($doctrine->unreadable as $label) {
            $actions[] = new SyncAction(SyncAction::FAILED, $label, detail: 'The fit is not readable EFT.');
        }

        foreach ($doctrine->fits as $fit) {
            $resolved = $resolve($fit);
            $shipTypeId = $resolved['shipTypeId'];
            if ($shipTypeId === null) {
                $uncertain = true;
                $actions[] = new SyncAction(SyncAction::FAILED, $fit->label(), $fit, detail: sprintf('"%s" is not a ship name SeAT knows.', $fit->shipName));
                continue;
            }
            if ($resolved['unresolved'] !== []) {
                // e.g. items newer than SeAT's item data: keep the linked copy as it is, still locked
                $existing = $find($shipTypeId, $fit->fitName, $doctrine->name);
                if ($existing !== null) {
                    $claimed[$existing['id']] = true;
                }
                $actions[] = new SyncAction(SyncAction::FAILED, $fit->label(), $fit, $shipTypeId, $existing['id'] ?? null,
                    'Unknown items: ' . implode(', ', $resolved['unresolved']) . '.');
                continue;
            }

            $existing = $find($shipTypeId, $fit->fitName, $doctrine->name);
            $key = $existing !== null ? 'id:' . $existing['id'] : $shipTypeId . ':' . mb_strtolower($fit->fitName);
            if (isset($seen[$key]) || isset($seen[':' . mb_strtolower($fit->fitName)])) {
                $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId,
                    detail: 'The doctrine has another fit with this name.');
                continue;
            }
            $seen[$key] = true;
            // within one doctrine a fit name is unique whatever the hull
            $seen[':' . mb_strtolower($fit->fitName)] = true;

            if ($existing === null) {
                $actions[] = new SyncAction(SyncAction::ADD, $fit->label(), $fit, $shipTypeId);
                continue;
            }
            $claimed[$existing['id']] = true;
            if (! $existing['sameHull']) {
                $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'],
                    'This doctrine already has a fit with this name on another hull.');
                continue;
            }
            if ($existing['sourceId'] === null || $existing['sourceId'] !== $sourceId) {
                if ($existing['sourceId'] === null && in_array($existing['id'], $linkIds, true)) {
                    $actions[] = new SyncAction(SyncAction::LINK, $fit->label(), $fit, $shipTypeId, $existing['id']);
                } elseif ($existing['sourceId'] !== null && $fit->shared !== null && ($existing['sharedId'] ?? null) === $fit->shared) {
                    $actions[] = self::sharedCopy($fit, $shipTypeId, $existing);
                } else {
                    $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'],
                        $existing['sourceId'] === null
                            ? SyncAction::HAND_FIT
                            : 'A fit from another followed doctrine has this name on this hull.');
                }
                continue;
            }

            $hash = UpstreamFit::hash($fit->eft);
            if (self::editedHere($existing)) {
                $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'], self::EDITED_HERE);
                continue;
            }
            if ($existing['missing']) {
                $actions[] = new SyncAction(SyncAction::RETURNED, $fit->label(), $fit, $shipTypeId, $existing['id']);
            } elseif ($existing['eftHash'] === $hash) {
                $actions[] = new SyncAction(SyncAction::UNCHANGED, $fit->label(), $fit, $shipTypeId, $existing['id']);
            } else {
                $actions[] = new SyncAction(SyncAction::UPDATE, $fit->label(), $fit, $shipTypeId, $existing['id']);
            }
        }

        foreach ($uncertain ? [] : $linked as $row) {
            if (! isset($claimed[$row['id']]) && ! $row['missing']) {
                $actions[] = new SyncAction(SyncAction::MISSING, $row['label'], fitId: $row['id']);
            }
        }

        return $actions;
    }

    /**
     * What to do about a copy of a shared fit another followed doctrine brought in.
     *
     * @param  array{id: int, eft: string, eftHash: ?string, missing: bool, ownerFollowing?: bool, ownerName?: string}  $existing
     */
    private static function sharedCopy(UpstreamFit $fit, int $shipTypeId, array $existing): SyncAction
    {
        $owner = $existing['ownerName'] ?? 'another doctrine';
        $following = $existing['ownerFollowing'] ?? true;
        if ($following && ! $existing['missing']) {
            return new SyncAction(SyncAction::SHARED, $fit->label(), $fit, $shipTypeId, $existing['id'],
                sprintf('Same fit as in %s; Hangar Check counts it once.', $owner));
        }
        // a fit edited here is never overwritten: it is fixed on the doctrine site
        if (self::editedHere($existing)) {
            return new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'], self::EDITED_HERE);
        }

        return new SyncAction(SyncAction::TAKE_OVER, $fit->label(), $fit, $shipTypeId, $existing['id'],
            $following
                ? sprintf('Taken over from %s, which no longer has this fit.', $owner)
                : sprintf('Taken over from %s, which is no longer followed.', $owner));
    }

    /** @param array{eft: string, eftHash: ?string} $existing */
    private static function editedHere(array $existing): bool
    {
        return $existing['eftHash'] !== null && UpstreamFit::hash($existing['eft']) !== $existing['eftHash'];
    }

    /**
     * The shared fit id to store when it is not stored yet or changed upstream.
     * Rows synced before shared ids were kept get theirs on their doctrine's next sync.
     *
     * @param  array{source_shared_id: mixed}  $row
     * @return array{source_shared_id?: ?int}
     */
    public static function sharedChange(array $row, UpstreamFit $fit): array
    {
        $stored = $row['source_shared_id'] === null ? null : (int) $row['source_shared_id'];

        return $stored === $fit->shared ? [] : ['source_shared_id' => $fit->shared];
    }

    /**
     * Names a linked fit should take from upstream. The database paired the two
     * ignoring case (and its collation may ignore more), but Hangar Check pairs
     * ships by the exact name, so a name changed only in case or spacing is copied.
     *
     * @param  array{fit_name: string, doctrine: string}  $row
     * @return array<'fit_name'|'doctrine', string>  only the columns that differ
     */
    public static function renames(array $row, UpstreamFit $fit, string $doctrineName): array
    {
        return array_filter(
            ['fit_name' => $fit->fitName, 'doctrine' => $doctrineName],
            static fn (string $name, string $column): bool => $name !== (string) $row[$column],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Whether a planned write still fits the fit's row as it is now, read under the row lock.
     * The plan was made from a plain read, so a user may have edited the fit since.
     *
     * @param  array{source_id: ?int, sync_state: ?string, eft: string, source_eft_hash: ?string, ship_type_id: int, source_shared_id?: mixed, owner_following?: bool}  $row
     *         owner_following: whether the doctrine the row is linked to is still followed (takeovers only)
     */
    public static function stillValid(SyncAction $action, array $row, int $sourceId): bool
    {
        $sourceOf = $row['source_id'] === null ? null : (int) $row['source_id'];
        $sameHull = (int) $row['ship_type_id'] === $action->shipTypeId;
        $notEditedHere = $row['source_eft_hash'] === null || UpstreamFit::hash((string) $row['eft']) === $row['source_eft_hash'];

        return match ($action->kind) {
            SyncAction::LINK => $sourceOf === null && $sameHull,
            SyncAction::UPDATE => $sourceOf === $sourceId && $row['sync_state'] === null && $sameHull && $notEditedHere,
            SyncAction::RETURNED => $sourceOf === $sourceId && $row['sync_state'] === 'missing' && $sameHull && $notEditedHere,
            SyncAction::MISSING => $sourceOf === $sourceId && $row['sync_state'] === null,
            SyncAction::UNCHANGED => $sourceOf === $sourceId,
            SyncAction::TAKE_OVER => $sourceOf !== null && $sourceOf !== $sourceId && $sameHull && $notEditedHere
                && $action->fit?->shared !== null
                && ($row['source_shared_id'] ?? null) !== null && (int) $row['source_shared_id'] === $action->fit->shared
                && ($row['sync_state'] === 'missing' || ($row['owner_following'] ?? true) === false),
            default => true,
        };
    }
}
