<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Corporations the current user may open Hangar Check and Doctrine Fits for.
 */
class CorporationAccess
{
    /** One permission covers both Hangar Check and Doctrine Fits. */
    public const ABILITY = 'corporation.doctrine_hangar_view';

    /** Global ability the sidebar checks; see SeatCustomPluginServiceProvider::add_menu_gate(). */
    public const MENU = 'custom-plugin.doctrine_menu';

    private const OFFICE_CACHE_KEY = 'custom-plugin:office-corporations';
    private const OFFICE_CACHE_SECONDS = 300;

    /** @var Collection<int, CorporationInfo>|null */
    private ?Collection $offices = null;

    /**
     * @return Collection<int, CorporationInfo>
     */
    public function allowed(): Collection
    {
        return $this->officeCorporations()
            ->filter(static fn (CorporationInfo $corporation) => Gate::allows(self::ABILITY, $corporation))
            ->values();
    }

    /**
     * True when the user holds the permission for at least one corporation; stops at the first match.
     */
    public function any(Authenticatable $user): bool
    {
        $gate = Gate::forUser($user);

        return $this->officeCorporations()
            ->contains(static fn (CorporationInfo $corporation) => $gate->allows(self::ABILITY, $corporation));
    }

    /**
     * Only corporations with an office in synced assets can have a hangar.
     * The id list is cached briefly because the sidebar asks on every page.
     *
     * @return Collection<int, CorporationInfo>
     */
    private function officeCorporations(): Collection
    {
        if ($this->offices !== null) {
            return $this->offices;
        }

        $ids = Cache::remember(self::OFFICE_CACHE_KEY, self::OFFICE_CACHE_SECONDS, static fn () => DB::table('corporation_assets')
            ->where('location_flag', 'OfficeFolder')
            ->distinct()
            ->pluck('corporation_id')
            ->all());

        return $this->offices = CorporationInfo::whereIn('corporation_id', $ids)->orderBy('name')->get();
    }
}
