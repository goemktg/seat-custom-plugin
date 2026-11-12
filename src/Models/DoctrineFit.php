<?php

namespace Goemktg\Seat\SeatCustomPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A doctrine fit owned by one corporation. The EFT text is the source of
 * truth for fitted modules; bays and container hold resolved quantities.
 *
 * @property int $id
 * @property int $corporation_id
 * @property string $doctrine
 * @property string $fit_name
 * @property int $ship_type_id
 * @property string $eft
 * @property array|null $bays
 * @property array|null $container
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon $updated_at
 */
class DoctrineFit extends Model
{
    public const TARGETS_TABLE = 'custom_plugin_doctrine_fit_targets';

    protected $table = 'custom_plugin_doctrine_fits';

    protected $fillable = [
        'corporation_id', 'doctrine', 'fit_name', 'ship_type_id', 'eft',
        'bays', 'container', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'ship_type_id' => 'integer',
        'sort_order' => 'integer',
        'bays' => 'array',
        'container' => 'array',
    ];

    /**
     * Edit-conflict token: changes whenever saved content or targets change,
     * even within the same second.
     */
    public function version(): string
    {
        return sha1(json_encode([
            $this->updated_at?->getTimestamp(), $this->doctrine, $this->fit_name, $this->eft,
            $this->bays, $this->container, $this->sort_order, $this->targets(),
        ]));
    }

    /**
     * @return array<int, int> staging id => target
     */
    public function targets(): array
    {
        return DB::table(self::TARGETS_TABLE)
            ->where('fit_id', $this->id)
            ->pluck('target', 'staging_id')
            ->map(static fn ($t) => (int) $t)
            ->all();
    }

    /**
     * Replace this fit's targets. Callers must pass only stagings of the
     * fit's own corporation.
     *
     * @param  array<int, int>  $targets  staging id => target
     */
    public function syncTargets(array $targets): void
    {
        DB::table(self::TARGETS_TABLE)->where('fit_id', $this->id)->delete();
        $now = now();
        $rows = [];
        foreach ($targets as $stagingId => $target) {
            if ($target > 0) {
                $rows[] = [
                    'fit_id' => $this->id, 'staging_id' => (int) $stagingId, 'target' => (int) $target,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        if ($rows !== []) {
            DB::table(self::TARGETS_TABLE)->insert($rows);
        }
    }

    /**
     * @return array<int, int> fit id => target at the staging
     */
    public static function targetsAtStaging(int $stagingId): array
    {
        return DB::table(self::TARGETS_TABLE)
            ->where('staging_id', $stagingId)
            ->pluck('target', 'fit_id')
            ->map(static fn ($t) => (int) $t)
            ->all();
    }
}
