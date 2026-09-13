<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $name
 * @property list<array<string, mixed>> $party
 * @property list<array<string, mixed>> $creatures
 * @property array<string, mixed>|null $difficulty
 * @property array<string, mixed> $metadata
 * @property-read Campaign $campaign
 */
class EncounterBuilderDraft extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['party' => 'array', 'creatures' => 'array', 'difficulty' => 'array', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
