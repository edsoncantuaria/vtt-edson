<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignModule extends Model
{
    protected $fillable = ['campaign_id', 'module_id', 'name', 'version', 'manifest', 'permissions', 'enabled'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'permissions' => 'array', 'enabled' => 'boolean'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
