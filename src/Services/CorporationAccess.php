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
 * Corporations the current user may open for a corporation-scoped ability.
 */
class CorporationAccess
{
    public const VIEW = 'corporation.doctrine_hangar_view';
    public const MANAGE = 'corporation.doctrine_fit_manage';

    /** Global abilities the sidebar checks; see SeatCustomPluginServiceProvider::add_menu_gates(). */
    public const VIEW_MENU = 'custom-plugin.hangar_check_menu';
    public const MANAGE_MENU = 'custom-plugin.doctrine_fits_menu';

    private const OFFICE_CACHE_KEY = 'custom-plugin:office-corporations';
    private const OFFICE_CACHE_SECONDS = 300;

    /** @var Collection<int, CorporationInfo>|null */
    private ?Collection $offices = null;

    /**
     * @return Collection<int, CorporationInfo>
     */
    public function allowed(string $ability): Collection
    {
        return $this->officeCorporations()
            ->filter(static fn (CorporationInfo $corporation) => Gate::allows($ability, $corporation))
            ->values();
    }

    /**
     * True when the user holds the ability for at least one corporation; stops at the first match.
     */
    public function any(Authenticatable $user, string $ability): bool
    {
        $gate = Gate::forUser($user);

        return $this->officeCorporations()
            ->contains(static fn (CorporationInfo $corporation) => $gate->allows($ability, $corporation));
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
