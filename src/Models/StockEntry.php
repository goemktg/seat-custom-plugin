<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One record of the stock ledger for a fit at a staging. Records are never
 * edited: a wrong one is deleted and recorded again. See Support\StockLedger.
 *
 * @property int $id
 * @property int $fit_id
 * @property int $staging_id
 * @property string $kind
 * @property int $quantity signed: purchases > 0, losses < 0, adjustments either way
 * @property \Illuminate\Support\Carbon $occurred_on
 * @property string|null $note
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'custom_plugin_doctrine_fit_stock_entries';

    protected $fillable = ['fit_id', 'staging_id', 'kind', 'quantity', 'occurred_on', 'note', 'created_by', 'created_by_name'];

    protected $casts = [
        'fit_id' => 'integer',
        'staging_id' => 'integer',
        'quantity' => 'integer',
        'occurred_on' => 'date',
        'created_by' => 'integer',
    ];

    /**
     * Ledger stock per fit at a staging, in one query. Fits without records are absent.
     *
     * @param  list<int>  $fitIds
     * @return array<int, int> fit id => stock
     */
    public static function stockAtStaging(int $stagingId, array $fitIds): array
    {
        if ($fitIds === []) {
            return [];
        }

        return DB::table((new self())->getTable())
            ->where('staging_id', $stagingId)
            ->whereIn('fit_id', $fitIds)
            ->groupBy('fit_id')
            ->select('fit_id', DB::raw('SUM(quantity) AS stock'))
            ->get()
            ->mapWithKeys(static fn ($row) => [(int) $row->fit_id => (int) $row->stock])
            ->all();
    }
}
