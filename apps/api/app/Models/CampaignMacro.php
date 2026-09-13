<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $owner_user_id
 * @property string $name
 * @property string|null $icon
 * @property list<array<string, mixed>> $commands
 * @property string $visibility
 * @property int|null $hotbar_slot
 * @property bool $enabled
 * @property-read Campaign $campaign
 */
class CampaignMacro extends Model
{
    protected $fillable = ['campaign_id', 'owner_user_id', 'name', 'icon', 'commands', 'visibility', 'hotbar_slot', 'enabled'];

    protected function casts(): array
    {
        return ['commands' => 'array', 'enabled' => 'boolean', 'hotbar_slot' => 'integer'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
