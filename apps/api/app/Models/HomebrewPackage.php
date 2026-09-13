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
 * @property string $version
 * @property bool $enabled
 * @property string|null $description
 * @property array<string, mixed> $metadata
 * @property-read Campaign $campaign
 * @property-read Collection<int, HomebrewEntry> $entries
 */
class HomebrewPackage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<HomebrewEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(HomebrewEntry::class);
    }
}
