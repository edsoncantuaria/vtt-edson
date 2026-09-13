<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $actor_id
 * @property int|null $source_document_id
 * @property string $name
 * @property array{unit:string,remaining?:int|null} $duration
 * @property list<array{path:string,mode:string,value:int|float|string}> $modifiers
 * @property list<string> $conditions
 * @property array<string, mixed> $metadata
 * @property bool $active
 * @property-read Actor $actor
 * @property-read ActorDocument|null $sourceDocument
 */
class ActiveEffect extends Model
{
    protected $fillable = ['actor_id', 'source_document_id', 'name', 'duration', 'modifiers', 'conditions', 'metadata', 'active'];

    protected function casts(): array
    {
        return ['duration' => 'array', 'modifiers' => 'array', 'conditions' => 'array', 'metadata' => 'array', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Actor, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    /** @return BelongsTo<ActorDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ActorDocument::class, 'source_document_id');
    }
}
