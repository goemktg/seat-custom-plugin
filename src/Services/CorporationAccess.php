<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Corporations the current user may open for a corporation-scoped ability.
 */
class CorporationAccess
{
    public const VIEW = 'corporation.doctrine_hangar_view';
    public const MANAGE = 'corporation.doctrine_fit_manage';

    /**
     * Only corporations with an office in synced assets can have a hangar.
     *
     * @return Collection<int, CorporationInfo>
     */
    public function allowed(string $ability): Collection
    {
        $ids = DB::table('corporation_assets')
            ->where('location_flag', 'OfficeFolder')
            ->distinct()
            ->pluck('corporation_id');

        return CorporationInfo::whereIn('corporation_id', $ids)
            ->orderBy('name')
            ->get()
            ->filter(static fn (CorporationInfo $corporation) => Gate::allows($ability, $corporation))
            ->values();
    }
}
