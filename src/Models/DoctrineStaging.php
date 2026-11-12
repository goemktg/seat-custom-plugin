<?php

namespace Goemktg\Seat\SeatCustomPlugin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A station or structure where a corporation keeps doctrine ships.
 *
 * @property int $id
 * @property int $corporation_id
 * @property int $location_id
 * @property string $name
 */
class DoctrineStaging extends Model
{
    protected $table = 'custom_plugin_doctrine_stagings';

    protected $fillable = ['corporation_id', 'location_id', 'name'];

    protected $casts = [
        'corporation_id' => 'integer',
        'location_id' => 'integer',
    ];
}
