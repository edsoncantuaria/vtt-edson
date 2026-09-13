<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $name
 * @property string $formula
 * @property bool $enabled
 * @property list<array<string, mixed>> $entries
 * @property array<string, mixed> $metadata
 * @property-read Campaign $campaign
 * @property-read Collection<int, RollTableRoll> $rolls
 */
class RollTable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'entries' => 'array', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<RollTableRoll, $this> */
    public function rolls(): HasMany
    {
        return $this->hasMany(RollTableRoll::class);
    }
}
