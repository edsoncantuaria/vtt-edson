<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $catalog_entry_id
 * @property int|null $scene_id
 * @property string $title
 * @property string $body
 * @property string $visibility
 * @property string|null $folder
 * @property array<string, mixed> $metadata
 * @property list<array<string, mixed>> $attachments
 * @property list<int> $shared_user_ids
 * @property-read Campaign $campaign
 */
class Journal extends Model
{
    protected $fillable = [
        'campaign_id', 'catalog_entry_id', 'scene_id', 'title', 'body', 'visibility',
        'folder', 'metadata', 'attachments', 'shared_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'attachments' => 'array',
            'shared_user_ids' => 'array',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
