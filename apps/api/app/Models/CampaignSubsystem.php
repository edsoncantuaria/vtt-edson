<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $catalog_entry_id
 * @property string $kind
 * @property string $name
 * @property array<string, mixed> $state
 * @property array<string, mixed> $metadata
 * @property bool $active
 * @property-read Campaign $campaign
 * @property-read CatalogEntry|null $catalogEntry
 */
class CampaignSubsystem extends Model
{
    protected $fillable = ['campaign_id', 'catalog_entry_id', 'kind', 'name', 'state', 'metadata', 'active'];

    protected function casts(): array
    {
        return ['state' => 'array', 'metadata' => 'array', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<CatalogEntry, $this> */
    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(CatalogEntry::class);
    }
}
