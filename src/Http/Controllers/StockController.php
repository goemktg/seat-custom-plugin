<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Goemktg\Seat\SeatCustomPlugin\Models\StockEntry;
use Goemktg\Seat\SeatCustomPlugin\Support\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

/**
 * The stock ledger of one fit at one staging, shown in a Hangar Check dialog.
 * The route group already checks the corporation permission; every lookup
 * here is limited to that corporation.
 */
class StockController extends Controller
{
    /** Error bag of the ledger form, kept apart from SeAT's page-wide alerts. */
    public const ERROR_BAG = 'stock';

    /** Records shown in the dialog; older ones still count towards stock. */
    public const HISTORY_LIMIT = 100;

    /** The dialog body (no layout), fetched by Hangar Check. */
    public function show(CorporationInfo $corporation, int $staging, int $fit)
    {
        [$stagingModel, $fitModel] = $this->resolve($corporation, $staging, $fit);

        return view('custom-plugin::hangar._stock', self::panel($corporation, $stagingModel, $fitModel));
    }

    public function store(Request $request, CorporationInfo $corporation, int $staging, int $fit)
    {
        [$stagingModel, $fitModel] = $this->resolve($corporation, $staging, $fit);
        $today = Carbon::now('UTC')->toDateString();

        $data = $request->validateWithBag(self::ERROR_BAG, [
            'kind' => ['required', Rule::in(array_keys(StockLedger::KINDS))],
            'ships' => ['required', 'integer', 'between:-' . StockLedger::MAX_SHIPS . ',' . StockLedger::MAX_SHIPS],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'occurred_on.before_or_equal' => 'The date cannot be later than today (' . $today . ' UTC).',
        ], [
            'occurred_on' => 'date',
        ]);

        try {
            $quantity = StockLedger::signed($data['kind'], (int) $data['ships']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ships' => $e->getMessage()])->errorBag(self::ERROR_BAG);
        }

        $note = trim((string) ($data['note'] ?? ''));
        StockEntry::create([
            'fit_id' => $fitModel->id,
            'staging_id' => $stagingModel->id,
            'kind' => $data['kind'],
            'quantity' => $quantity,
            'occurred_on' => $data['occurred_on'],
            'note' => $note === '' ? null : $note,
            'created_by' => $request->user()->id,
            'created_by_name' => $request->user()->name,
        ]);

        return $this->backToHangar($corporation, $stagingModel, $fitModel, sprintf(
            'Recorded %s of %d %s.',
            strtolower(StockLedger::KINDS[$data['kind']]), abs($quantity), abs($quantity) === 1 ? 'ship' : 'ships',
        ));
    }

    public function destroy(CorporationInfo $corporation, int $entry)
    {
        $model = StockEntry::findOrFail($entry);
        [$stagingModel, $fitModel] = $this->resolve($corporation, $model->staging_id, $model->fit_id);
        $model->delete();

        return $this->backToHangar($corporation, $stagingModel, $fitModel, 'Deleted the record.');
    }

    /**
     * What the dialog body needs.
     *
     * @return array<string, mixed>
     */
    public static function panel(CorporationInfo $corporation, DoctrineStaging $staging, DoctrineFit $fit): array
    {
        $query = StockEntry::where('fit_id', $fit->id)->where('staging_id', $staging->id);

        return [
            'corporation' => $corporation,
            'staging' => $staging,
            'fit' => $fit,
            'stock' => (int) (clone $query)->sum('quantity'),
            'count' => (clone $query)->count(),
            'entries' => (clone $query)->orderByDesc('occurred_on')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get(),
            'today' => Carbon::now('UTC')->toDateString(),
        ];
    }

    /**
     * The staging and fit, both owned by the corporation in the URL.
     *
     * @return array{0: DoctrineStaging, 1: DoctrineFit}
     */
    private function resolve(CorporationInfo $corporation, int $staging, int $fit): array
    {
        return [
            DoctrineStaging::where('corporation_id', $corporation->corporation_id)->findOrFail($staging),
            DoctrineFit::where('corporation_id', $corporation->corporation_id)->findOrFail($fit),
        ];
    }

    private function backToHangar(CorporationInfo $corporation, DoctrineStaging $staging, DoctrineFit $fit, string $status)
    {
        return redirect()
            ->route('custom-plugin.hangar.show', [$corporation->corporation_id, 'staging' => $staging->id])
            ->with('stock_fit', $fit->id)
            ->with('stock_status', $status);
    }
}
