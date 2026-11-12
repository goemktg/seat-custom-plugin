<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stations and structures where a corporation has an office, with names.
 */
class Locations
{
    /**
     * @return array<int, string> location id => display name
     */
    public function officeLocations(int $corporationId): array
    {
        $ids = DB::table('corporation_assets')
            ->where('corporation_id', $corporationId)
            ->where('location_flag', 'OfficeFolder')
            ->distinct()
            ->pluck('location_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        return $this->names($ids);
    }

    /**
     * @param  list<int>  $locationIds
     * @return array<int, string>
     */
    public function names(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        $names = DB::table('universe_stations')->whereIn('station_id', $locationIds)->pluck('name', 'station_id')->all()
            + DB::table('universe_structures')->whereIn('structure_id', $locationIds)->pluck('name', 'structure_id')->all()
            // NPC stations are also in the SDE even before SeAT resolves them from ESI
            + DB::table('staStations')->whereIn('stationID', $locationIds)->pluck('stationName', 'stationID')->all();

        $out = [];
        foreach ($locationIds as $id) {
            $out[$id] = (string) ($names[$id] ?? ('Location #' . $id));
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }
}
