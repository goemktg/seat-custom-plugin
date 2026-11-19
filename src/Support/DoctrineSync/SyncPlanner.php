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
 */
final class SyncPlanner
{
    /**
     * @param  int|null  $sourceId  the followed doctrine's row, null before it is first followed
     * @param  list<array{id: int, label: string, missing: bool}>  $linked  every fit of this source
     * @param  callable(UpstreamFit): array{shipTypeId: ?int, unresolved: list<string>}  $resolve  against SeAT's item data
     * @param  callable(int, string, string): ?array{id: int, sourceId: ?int, eft: string, eftHash: ?string, missing: bool, sameHull: bool}  $find
     *         the corporation's fit that the unique keys would pair with (hull, fit name, doctrine name), as the database compares names
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
                } else {
                    $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'],
                        $existing['sourceId'] === null
                            ? SyncAction::HAND_FIT
                            : 'A fit from another followed doctrine has this name on this hull.');
                }
                continue;
            }

            $hash = UpstreamFit::hash($fit->eft);
            if ($existing['eftHash'] !== null && UpstreamFit::hash($existing['eft']) !== $existing['eftHash']) {
                $actions[] = new SyncAction(SyncAction::HELD, $fit->label(), $fit, $shipTypeId, $existing['id'],
                    'The EFT was changed here since the last sync.');
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
     * Whether a planned write still fits the fit's row as it is now, read under the row lock.
     * The plan was made from a plain read, so a user may have edited the fit since.
     *
     * @param  array{source_id: ?int, sync_state: ?string, eft: string, source_eft_hash: ?string, ship_type_id: int}  $row
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
            default => true,
        };
    }
}
