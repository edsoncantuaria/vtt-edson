<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $actor_id
 * @property int|null $catalog_entry_id
 * @property string $kind
 * @property string $name
 * @property string|null $slug
 * @property string|null $source
 * @property array<string, mixed> $data
 * @property array<string, mixed> $overrides
 * @property int $quantity
 * @property bool $equipped
 * @property bool $prepared
 * @property bool $attuned
 * @property array{value:int,max:int,reset:string,recoveryFormula?:string}|null $charges
 * @property int $sort
 * @property-read Actor $actor
 * @property-read CatalogEntry|null $catalogEntry
 */
class ActorDocument extends Model
{
    protected $fillable = [
        'actor_id', 'catalog_entry_id', 'kind', 'name', 'slug', 'source', 'data', 'overrides',
        'quantity', 'equipped', 'prepared', 'attuned', 'charges', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array', 'overrides' => 'array', 'charges' => 'array',
            'equipped' => 'boolean', 'prepared' => 'boolean', 'attuned' => 'boolean',
            'quantity' => 'integer', 'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Actor, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    /** @return BelongsTo<CatalogEntry, $this> */
    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(CatalogEntry::class);
    }

    /** @return HasMany<ActiveEffect, $this> */
    public function effects(): HasMany
    {
        return $this->hasMany(ActiveEffect::class, 'source_document_id');
    }
}
