<?php

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Goemktg\Seat\SeatCustomPlugin\Services\CorporationAccess;
use Goemktg\Seat\SeatCustomPlugin\Services\HangarCheckService;
use Illuminate\Http\Request;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

class HangarCheckController extends Controller
{
    public function index(CorporationAccess $access)
    {
        $corporations = $access->allowed(CorporationAccess::VIEW);
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

        return view('custom-plugin::hangar.show', [
            'corporation' => $corporation,
            'corporations' => $access->allowed(CorporationAccess::VIEW),
            'stagings' => $stagings,
            'staging' => $staging,
            'result' => $staging !== null ? $service->report($staging) : null,
        ]);
    }
}
