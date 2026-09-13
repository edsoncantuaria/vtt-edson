<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAsset extends Model
{
    protected $fillable = ['campaign_id', 'uploader_user_id', 'name', 'kind', 'path', 'mime', 'size_bytes', 'sha256', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
