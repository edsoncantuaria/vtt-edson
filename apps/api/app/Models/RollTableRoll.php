<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $roll_table_id
 * @property int $user_id
 * @property int $total
 * @property array<string, mixed> $result
 * @property Carbon $created_at
 * @property-read RollTable $table
 */
class RollTableRoll extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<RollTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RollTable::class, 'roll_table_id');
    }
}
