<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $roll_table_roll_id
 * @property string $name
 * @property list<array<string, mixed>> $items
 * @property array<string, int|float> $currency
 * @property array<string, mixed> $metadata
 * @property string $status
 * @property int|null $applied_actor_id
 * @property Carbon|null $applied_at
 * @property-read Campaign $campaign
 * @property-read Actor|null $actor
 */
class LootResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'currency' => 'array', 'metadata' => 'array', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Actor, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'applied_actor_id');
    }
}
