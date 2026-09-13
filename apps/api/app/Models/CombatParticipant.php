<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $combat_id
 * @property int|null $actor_id
 * @property string|null $token_id
 * @property string $name
 * @property string|null $img_path
 * @property int|null $initiative
 * @property bool $hidden
 * @property int $sort
 * @property-read Combat $combat
 * @property-read Actor|null $actor
 */
class CombatParticipant extends Model
{
    protected $fillable = [
        'combat_id',
        'actor_id',
        'token_id',
        'name',
        'img_path',
        'initiative',
        'hidden',
        'sort',
    ];

    protected function casts(): array
    {
        return ['hidden' => 'boolean'];
    }

    /** @return BelongsTo<Combat, $this> */
    public function combat(): BelongsTo
    {
        return $this->belongsTo(Combat::class);
    }

    /** @return BelongsTo<Actor, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'combatId' => $this->combat_id,
            'actorId' => $this->actor_id,
            'tokenId' => $this->token_id,
            'name' => $this->name,
            'imgUrl' => $this->img_path ? url('storage/'.$this->img_path) : null,
            'initiative' => $this->initiative,
            'hidden' => $this->hidden,
            'sort' => $this->sort,
        ];
    }
}
