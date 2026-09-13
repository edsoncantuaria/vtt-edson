<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $scene_id
 * @property int $round
 * @property int $turn
 * @property bool $is_active
 * @property-read Scene $scene
 * @property-read Collection<int, CombatParticipant> $participants
 */
class Combat extends Model
{
    protected $fillable = ['scene_id', 'round', 'turn', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /** @return HasMany<CombatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CombatParticipant::class)->orderBy('sort');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'sceneId' => $this->scene_id,
            'round' => $this->round,
            'turn' => $this->turn,
            'isActive' => $this->is_active,
            'participants' => $this->participants->map(fn (CombatParticipant $p) => $p->toPayload())->all(),
        ];
    }

    public function payloadFor(User $user): array
    {
        $payload = $this->toPayload();
        $scene = $this->scene;
        if ($scene->campaign->roleFor($user) === 'gm') {
            return $payload;
        }
        $tokens = collect($scene->state['tokens']);
        $visible = array_column($scene->visibleTokensFor($user), 'id');
        $hidden = $tokens->filter(fn ($token) => ! in_array($token['id'], $visible, true))->pluck('id')->all();
        $readable = $scene->campaign->actors()->where(fn ($query) => $query->where('owner_user_id', $user->id)->orWhere('shared', true))->pluck('id')->all();
        $activeId = $payload['participants'][$payload['turn']]['id'] ?? null;
        $payload['participants'] = array_values(array_filter($payload['participants'], fn ($p) => ! $p['hidden'] && ! in_array($p['tokenId'], $hidden)));
        $active = array_search($activeId, array_column($payload['participants'], 'id'), true);
        $payload['turn'] = $active === false ? -1 : $active;
        foreach ($payload['participants'] as &$participant) {
            if (! in_array($participant['actorId'], $readable)) {
                $participant['actorId'] = null;
            }
        }
        unset($participant);

        return $payload;
    }
}
