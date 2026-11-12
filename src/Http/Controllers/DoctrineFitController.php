<?php

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Goemktg\Seat\SeatCustomPlugin\Services\CorporationAccess;
use Goemktg\Seat\SeatCustomPlugin\Services\FitInput;
use Goemktg\Seat\SeatCustomPlugin\Services\Locations;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

class DoctrineFitController extends Controller
{
    public function pick(CorporationAccess $access)
    {
        $corporations = $access->allowed(CorporationAccess::MANAGE);
        if ($corporations->count() === 1) {
            return redirect()->route('custom-plugin.fits.index', $corporations->first()->corporation_id);
        }

        return view('custom-plugin::hangar.pick', [
            'corporations' => $corporations,
            'route' => 'custom-plugin.fits.index',
            'title' => 'Doctrine Fits',
        ]);
    }

    public function index(CorporationInfo $corporation, Locations $locations)
    {
        $stagings = DoctrineStaging::where('corporation_id', $corporation->corporation_id)->orderBy('name')->get();
        $fits = DoctrineFit::where('corporation_id', $corporation->corporation_id)
            ->orderBy('sort_order')->orderBy('doctrine')->orderBy('fit_name')->get();
        $targets = DB::table(DoctrineFit::TARGETS_TABLE)
            ->whereIn('fit_id', $fits->pluck('id'))
            ->get()
            ->groupBy('fit_id');
        $users = DB::table('users')->whereIn('id', $fits->pluck('updated_by')->filter())->pluck('name', 'id');

        $offices = $locations->officeLocations($corporation->corporation_id);
        $available = array_diff_key($offices, array_flip($stagings->pluck('location_id')->all()));

        return view('custom-plugin::fits.index', [
            'corporation' => $corporation,
            'stagings' => $stagings,
            'fits' => $fits,
            'targets' => $targets,
            'users' => $users,
            'availableLocations' => $available,
            'hulls' => DB::table('invTypes')->whereIn('typeID', $fits->pluck('ship_type_id'))->pluck('typeName', 'typeID'),
        ]);
    }

    public function create(CorporationInfo $corporation)
    {
        return $this->form($corporation, null);
    }

    public function store(Request $request, CorporationInfo $corporation, FitInput $input)
    {
        $stagings = $this->stagingIds($corporation);
        $valid = $input->validate($request, $corporation->corporation_id, $stagings);

        self::guardDuplicate(fn () => DB::transaction(function () use ($valid, $request) {
            $fit = DoctrineFit::create($valid['attributes'] + [
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $fit->syncTargets($valid['targets']);
        }));

        return redirect()->route('custom-plugin.fits.index', $corporation->corporation_id)
            ->with('success', sprintf('Saved fit "%s".', $valid['attributes']['fit_name']));
    }

    public function edit(CorporationInfo $corporation, int $fit)
    {
        return $this->form($corporation, $this->findFit($corporation, $fit));
    }

    public function update(Request $request, CorporationInfo $corporation, int $fit, FitInput $input)
    {
        $model = $this->findFit($corporation, $fit);
        $valid = $input->validate($request, $corporation->corporation_id, $this->stagingIds($corporation), $model);

        self::guardDuplicate(fn () => DB::transaction(function () use ($model, $valid, $request) {
            // re-check under a row lock so two concurrent saves cannot both pass
            $locked = DoctrineFit::whereKey($model->id)->lockForUpdate()->firstOrFail();
            FitInput::assertCurrent($locked, $request->input('version'));
            $locked->fill($valid['attributes'] + ['updated_by' => $request->user()->id])->save();
            $locked->syncTargets($valid['targets']);
        }));

        return redirect()->route('custom-plugin.fits.index', $corporation->corporation_id)
            ->with('success', sprintf('Saved fit "%s".', $valid['attributes']['fit_name']));
    }

    public function destroy(CorporationInfo $corporation, int $fit)
    {
        $model = $this->findFit($corporation, $fit);
        $model->delete();

        return redirect()->route('custom-plugin.fits.index', $corporation->corporation_id)
            ->with('success', sprintf('Deleted fit "%s".', $model->fit_name));
    }

    public function storeStaging(Request $request, CorporationInfo $corporation, Locations $locations)
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);
        $offices = $locations->officeLocations($corporation->corporation_id);
        $locationId = (int) $data['location_id'];
        if (! isset($offices[$locationId])) {
            return back()->withErrors(['location_id' => 'Pick a station or structure where this corporation has an office.']);
        }

        self::guardDuplicate(fn () => DoctrineStaging::firstOrCreate(
            ['corporation_id' => $corporation->corporation_id, 'location_id' => $locationId],
            ['name' => $offices[$locationId]],
        ), 'location_id', 'This staging was just added by someone else.');

        return back()->with('success', sprintf('Added staging %s.', $offices[$locationId]));
    }

    public function destroyStaging(CorporationInfo $corporation, int $staging)
    {
        $model = DoctrineStaging::where('corporation_id', $corporation->corporation_id)->findOrFail($staging);
        $model->delete();

        return back()->with('success', sprintf('Removed staging %s and its targets.', $model->name));
    }

    private function form(CorporationInfo $corporation, ?DoctrineFit $fit)
    {
        $stagings = DoctrineStaging::where('corporation_id', $corporation->corporation_id)->orderBy('name')->get();

        return view('custom-plugin::fits.form', [
            'corporation' => $corporation,
            'fit' => $fit,
            'stagings' => $stagings,
            'targets' => $fit?->targets() ?? [],
            'bayFields' => FitInput::BAY_FIELDS,
        ]);
    }

    /**
     * A unique-key race between the duplicate check and the insert becomes a
     * form error instead of a 500.
     */
    private static function guardDuplicate(\Closure $write, string $field = 'fit_name', string $message = 'A fit with this doctrine and name was just saved by someone else. Choose another name.'): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages([$field => $message]);
            }
            throw $e;
        }
    }

    private function findFit(CorporationInfo $corporation, int $fit): DoctrineFit
    {
        return DoctrineFit::where('corporation_id', $corporation->corporation_id)->findOrFail($fit);
    }

    /** @return list<int> */
    private function stagingIds(CorporationInfo $corporation): array
    {
        return DoctrineStaging::where('corporation_id', $corporation->corporation_id)
            ->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }
}
