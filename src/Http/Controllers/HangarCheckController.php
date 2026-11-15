<?php

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Goemktg\Seat\SeatCustomPlugin\Models\StockEntry;
use Goemktg\Seat\SeatCustomPlugin\Services\CorporationAccess;
use Goemktg\Seat\SeatCustomPlugin\Services\HangarCheckService;
use Goemktg\Seat\SeatCustomPlugin\Support\LastCorporation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

class HangarCheckController extends Controller
{
    public function index(Request $request, CorporationAccess $access)
    {
        $corporations = $access->allowed();
        $last = LastCorporation::pick($corporations->pluck('corporation_id')->all(), $request->cookie(LastCorporation::COOKIE));
        if ($last !== null) {
            return redirect()->route('custom-plugin.hangar.show', $last);
        }
        if ($corporations->count() === 1) {
            return redirect()->route('custom-plugin.hangar.show', $corporations->first()->corporation_id);
        }

        return view('custom-plugin::hangar.pick', [
            'corporations' => $corporations,
            'route' => 'custom-plugin.hangar.show',
            'title' => 'Hangar Check',
        ]);
    }

    public function show(Request $request, CorporationInfo $corporation, CorporationAccess $access, HangarCheckService $service)
    {
        $stagings = DoctrineStaging::where('corporation_id', $corporation->corporation_id)->orderBy('name')->get();
        $staging = $stagings->firstWhere('id', (int) $request->query('staging')) ?? $stagings->first();
        Cookie::queue(LastCorporation::COOKIE, (string) $corporation->corporation_id, LastCorporation::MINUTES);

        $result = $staging !== null ? $service->report($staging) : null;
        $stock = [];
        $stockPanel = null;
        if ($result !== null) {
            $fitIds = array_map(static fn (array $row): int => (int) $row['fitId'], $result['report']->fits);
            $stock = StockEntry::stockAtStaging($staging->id, $fitIds);

            // after recording or deleting (or a rejected record), reopen that fit's ledger
            $reopen = (int) (session('stock_fit') ?? old('stock_fit') ?? 0);
            if (in_array($reopen, $fitIds, true)) {
                $fit = DoctrineFit::where('corporation_id', $corporation->corporation_id)->find($reopen);
                $stockPanel = $fit !== null ? StockController::panel($corporation, $staging, $fit) : null;
            }
        }

        return view('custom-plugin::hangar.show', [
            'corporation' => $corporation,
            'corporations' => $access->allowed(),
            'stagings' => $stagings,
            'staging' => $staging,
            'result' => $result,
            'stock' => $stock,
            'stockPanel' => $stockPanel,
        ]);
    }
}
