<?php

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Goemktg\Seat\SeatCustomPlugin\Models\StockEntry;
use Goemktg\Seat\SeatCustomPlugin\Services\CorporationAccess;
use Goemktg\Seat\SeatCustomPlugin\Services\FitInput;
use Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync\DoctrineSync;
use Goemktg\Seat\SeatCustomPlugin\Services\Locations;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SourceStatus;
use Goemktg\Seat\SeatCustomPlugin\Support\DuplicateFit;
use Goemktg\Seat\SeatCustomPlugin\Support\FitMove;
use Illuminate\Support\Carbon;
use Goemktg\Seat\SeatCustomPlugin\Support\LastCorporation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

class DoctrineFitController extends Controller
{
    public function pick(Request $request, CorporationAccess $access)
    {
        $corporations = $access->allowed();
        $last = LastCorporation::pick($corporations->pluck('corporation_id')->all(), $request->cookie(LastCorporation::COOKIE));
        if ($last !== null) {
            return redirect()->route('custom-plugin.fits.index', $last);
        }
        if ($corporations->count() === 1) {
            return redirect()->route('custom-plugin.fits.index', $corporations->first()->corporation_id);
        }

        return view('custom-plugin::hangar.pick', [
            'corporations' => $corporations,
            'route' => 'custom-plugin.fits.index',
            'title' => 'Doctrine Fits',
        ]);
    }

    public function index(CorporationInfo $corporation, Locations $locations, CorporationAccess $access, DoctrineSync $sync)
    {
        Cookie::queue(LastCorporation::COOKIE, (string) $corporation->corporation_id, LastCorporation::MINUTES);
        $stagings = DoctrineStaging::where('corporation_id', $corporation->corporation_id)->orderBy('name')->get();
        $fits = DoctrineFit::where('corporation_id', $corporation->corporation_id)
            ->orderBy('sort_order')->orderBy('doctrine')->orderBy('fit_name')->get();
        $targets = DB::table(DoctrineFit::TARGETS_TABLE)
            ->whereIn('fit_id', $fits->pluck('id'))
            ->get()
            ->groupBy('fit_id');
        $users = DB::table('users')->whereIn('id', $fits->pluck('updated_by')->filter())->pluck('name', 'id');
        $hulls = DB::table('invTypes')->whereIn('typeID', $fits->pluck('ship_type_id'))->pluck('typeName', 'typeID');

        $offices = $locations->officeLocations($corporation->corporation_id);
        $available = array_diff_key($offices, array_flip($stagings->pluck('location_id')->all()));

        return view('custom-plugin::fits.index', [
            'corporation' => $corporation,
            'corporations' => $access->allowed(),
            'stagings' => $stagings,
            'fits' => $fits,
            'targets' => $targets,
            'users' => $users,
            'availableLocations' => $available,
            'hulls' => $hulls,
            'doctrineSite' => $this->doctrineSite($corporation, $sync),
            'shared' => DoctrineSync::sharedFits($corporation->corporation_id),
            'move' => $this->moveData($fits, $targets, $stagings, $hulls),
        ]);
    }

    /**
     * The status card's data, or null when the import is off for the corporation.
     * Reads only this database; the doctrine site is asked when the import window opens.
     *
     * @return array{sources: list<array<string, mixed>>, recent: \Illuminate\Support\Collection}|null
     */
    private function doctrineSite(CorporationInfo $corporation, DoctrineSync $sync): ?array
    {
        if (! $sync->enabledFor($corporation->corporation_id)) {
            return null;
        }
        $sources = DB::table(DoctrineSync::SOURCES)->where('corporation_id', $corporation->corporation_id)
            ->where('following', true)->orderBy('name')->get()
            ->map(static fn ($s) => [
                'name' => (string) $s->name,
                'status' => $s->upstream_status,
                'version' => $s->upstream_version,
                'checkedAt' => $s->last_checked_at === null ? null : Carbon::parse($s->last_checked_at),
                'error' => $s->last_error,
                'state' => SourceStatus::of($s->last_checked_at, $s->last_error, $s->last_report),
                'problems' => SourceStatus::problems($s->last_report),
                'shared' => SourceStatus::sharedCount($s->last_report),
            ])->all();

        return [
            'sources' => $sources,
            'recent' => DB::table(DoctrineSync::EVENTS)->where('corporation_id', $corporation->corporation_id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get(),
        ];
    }

    /**
     * What the Move window needs: fits that can be moved from (gone from the doctrine
     * site, with counts or stock), every fit as a choice, and counts per staging.
     *
     * @return array{from: array<int, array{name: string, label: string, ship: int, targets: array<int, int>, entries: array<int, int>}>, fits: list<array{id: int, name: string, label: string, ship: int, targets: array<int, int>}>, stagings: array<int, string>}
     */
    private function moveData($fits, $targets, $stagings, $hulls): array
    {
        $label = static fn ($f): string => sprintf('%s (%s) · %s', $f->fit_name, $hulls[$f->ship_type_id] ?? ('Type #' . $f->ship_type_id), $f->doctrine);
        $counts = static fn ($f): array => $targets->get($f->id, collect())
            ->mapWithKeys(static fn ($t) => [(int) $t->staging_id => (int) $t->target])->filter()->all();
        $gone = $fits->filter(static fn ($f) => FitMove::canMoveFrom($f->source_id, $f->sync_state));
        $entries = $gone->isEmpty() ? collect() : DB::table((new StockEntry())->getTable())
            ->whereIn('fit_id', $gone->pluck('id'))->groupBy('fit_id', 'staging_id')
            ->select('fit_id', 'staging_id', DB::raw('COUNT(*) AS entries'))->get()->groupBy('fit_id');
        $from = [];
        foreach ($gone as $f) {
            $byStaging = $entries->get($f->id, collect())->mapWithKeys(static fn ($e) => [(int) $e->staging_id => (int) $e->entries])->all();
            $required = $counts($f);
            if ($required !== [] || $byStaging !== []) {
                $from[(int) $f->id] = ['name' => (string) $f->fit_name, 'label' => $label($f), 'ship' => (int) $f->ship_type_id, 'targets' => $required, 'entries' => $byStaging];
            }
        }

        return [
            'from' => $from,
            'fits' => $from === [] ? [] : $fits->map(static fn ($f) => [
                'id' => (int) $f->id, 'name' => (string) $f->fit_name, 'label' => $label($f), 'ship' => (int) $f->ship_type_id, 'targets' => $counts($f),
            ])->values()->all(),
            'stagings' => $stagings->mapWithKeys(static fn ($s) => [(int) $s->id => (string) $s->name])->all(),
        ];
    }

    /**
     * Moves the required counts and stock ledger of a fit gone from the doctrine
     * site to another fit, e.g. the one it was renamed to there. The gone fit stays.
     */
    public function move(Request $request, CorporationInfo $corporation, int $fit)
    {
        $to = (int) $request->validate(['to' => ['required', 'integer', 'min:1']])['to'];
        if ($to === $fit) {
            throw ValidationException::withMessages(['to' => 'Choose another fit to move to.']);
        }

        $user = $request->user();
        $message = DB::transaction(function () use ($corporation, $fit, $to, $user): string {
            // both rows locked in id order, so two moves between the same fits cannot deadlock
            $rows = DoctrineFit::where('corporation_id', $corporation->corporation_id)->whereIn('id', [$fit, $to])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $rows->get($fit) ?? abort(404);
            $into = $rows->get($to);
            if ($into === null) {
                throw ValidationException::withMessages(['to' => 'Choose a fit of this corporation to move to.']);
            }
            if (! FitMove::canMoveFrom($from->source_id, $from->sync_state)) {
                throw ValidationException::withMessages(['fit' => sprintf('"%s" is still on the doctrine site. Only a fit gone from it can be moved.', $from->fit_name)]);
            }

            $targets = DB::table(DoctrineFit::TARGETS_TABLE)->whereIn('fit_id', [$fit, $to])->lockForUpdate()->get()->groupBy('fit_id');
            $countsOf = static fn (int $id): array => $targets->get($id, collect())
                ->mapWithKeys(static fn ($t) => [(int) $t->staging_id => (int) $t->target])->all();
            $fromCounts = $countsOf($fit);
            $clashes = FitMove::clashes($fromCounts, $countsOf($to));
            if ($clashes !== []) {
                $names = DoctrineStaging::whereIn('id', $clashes)->orderBy('name')->pluck('name')->all();
                throw ValidationException::withMessages(['to' => sprintf(
                    '"%s" already has a required count at %s. Clear it on that fit\'s Edit page first, then move.', $into->fit_name, implode(', ', $names))]);
            }

            $moving = array_keys(array_filter($fromCounts, static fn (int $n): bool => $n > 0));
            if ($moving !== []) {
                // a zero row of the other fit would collide with the (fit, staging) key
                DB::table(DoctrineFit::TARGETS_TABLE)->where('fit_id', $to)->whereIn('staging_id', $moving)->delete();
                DB::table(DoctrineFit::TARGETS_TABLE)->where('fit_id', $fit)->whereIn('staging_id', $moving)
                    ->update(['fit_id' => $to, 'updated_at' => now()]);
            }
            DB::table(DoctrineFit::TARGETS_TABLE)->where('fit_id', $fit)->delete();
            $entries = DB::table((new StockEntry())->getTable())->where('fit_id', $fit)->update(['fit_id' => $to]);
            $summary = FitMove::summary((string) $from->fit_name, (string) $into->fit_name, count($moving), $entries);

            // the ledger's records were moved, not rewritten: the history says who moved them and when
            DB::table(DoctrineSync::EVENTS)->insert([
                'run_id' => (string) Str::ulid(), 'corporation_id' => $corporation->corporation_id,
                'source_id' => $from->source_id, 'fit_id' => $to, 'kind' => FitMove::EVENT,
                'doctrine_label' => mb_substr((string) $from->doctrine, 0, 100),
                'fit_label' => mb_substr(sprintf('%s → %s', $from->fit_name, $into->fit_name), 0, 255),
                'detail' => mb_substr($summary, 0, 500),
                'created_by' => $user?->id, 'created_by_name' => $user?->name, 'created_at' => now(),
            ]);

            return $summary;
        });

        return redirect()->route('custom-plugin.fits.index', $corporation->corporation_id)->with('success', $message);
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
        // FitInput keeps a synced fit's doctrine, name and EFT whatever the form sent; the version
        // check under the row lock below still refuses a save if a sync changed them meanwhile
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
        if ($model->siteLocked()) {
            // the next sync would only add it again
            return back()->withErrors(['fit' => sprintf('"%s" comes from the doctrine site. Remove it there; once it is gone from the site you can delete it here.', $model->fit_name)]);
        }
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
        $records = StockEntry::where('staging_id', $model->id)->count();
        if ($records > 0) {
            return back()->withErrors(['staging' => sprintf(
                'Cannot remove %s: the stock ledger has %d %s there. Delete %s in Hangar Check first.',
                $model->name, $records, $records === 1 ? 'record' : 'records', $records === 1 ? 'it' : 'them',
            )]);
        }
        try {
            $model->delete();
        } catch (QueryException $e) {
            // a record was added after the check; the foreign key refused the delete
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                return back()->withErrors(['staging' => sprintf('Cannot remove %s: the stock ledger has records there. Delete them in Hangar Check first.', $model->name)]);
            }
            throw $e;
        }

        return back()->with('success', sprintf('Removed staging %s and its required ship counts.', $model->name));
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
            'locked' => $fit !== null && $fit->siteLocked(),
            'source' => $fit?->source_id === null ? null
                : DB::table(DoctrineSync::SOURCES)->where('id', $fit->source_id)->first(['name', 'upstream_version']),
        ]);
    }

    /**
     * A unique-key race between the duplicate check and the insert becomes a
     * form error instead of a 500. Without a message, a fit save names the
     * uniqueness rule the key enforces.
     */
    private static function guardDuplicate(\Closure $write, string $field = 'fit_name', ?string $message = null): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages([$field => $message ?? DuplicateFit::messageForDriverError((string) ($e->errorInfo[2] ?? $e->getMessage()))]);
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
