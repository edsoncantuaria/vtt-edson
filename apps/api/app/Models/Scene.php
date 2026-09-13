<?php

namespace App\Models;

use App\Support\SceneVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $campaign_id
 * @property bool $published
 * @property string|null $import_key
 * @property string $name
 * @property string|null $background_path
 * @property array<string, mixed> $state
 * @property-read Campaign $campaign
 * @property-read Collection<int, SceneMember> $members
 */
class Scene extends Model
{
    protected $fillable = [
        'campaign_id',
        'published',
        'import_key',
        'name',
        'background_path',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'published' => 'boolean',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<SceneMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SceneMember::class);
    }

    public function memberFor(User $user): ?SceneMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }

    public function visibleTokensFor(User $user): array
    {
        if ($this->campaign->canManage($user)) {
            return $this->state['tokens'];
        }

        return SceneVisibility::tokens($this->state, $user, $this->perceptionViewers($user));
    }

    /** Public token presence does not grant access to its private stat block. */
    public function stateFor(User $user): array
    {
        $state = $this->state;
        if ($this->campaign->canManage($user)
            || ($this->campaign->roleFor($user) !== 'observer'
                && CampaignResourcePermission::permits($this->campaign, $user, 'scene', $this->id, 'edit'))) {
            return $state;
        }
        $viewers = $this->perceptionViewers($user);
        $geometry = SceneVisibility::doors($state, $viewers);
        $state['walls'] = $geometry['walls'];
        $state['doors'] = $geometry['doors'];
        $grantedActorIds = CampaignResourcePermission::query()->where('campaign_id', $this->campaign_id)
            ->where('user_id', $user->id)->where('resource_type', 'actor')->pluck('resource_id');
        $readable = $this->campaign->actors()->where(fn ($query) => $query->where('owner_user_id', $user->id)->orWhere('shared', true)->orWhereIn('id', $grantedActorIds))->pluck('id')->all();
        $state['tokens'] = SceneVisibility::tokens($this->state, $user, $viewers);
        foreach (['drawings', 'labels', 'tiles', 'regions'] as $collection) {
            $state[$collection] = array_values(array_filter($state[$collection] ?? [], fn ($item) => ! ($item['hidden'] ?? false)));
        }
        $portraits = $this->campaign->actors()->whereIn('id', array_filter(array_column($state['tokens'], 'actorId')))->get()->keyBy('id');
        foreach ($state['tokens'] as &$token) {
            $actor = $portraits->get($token['actorId'] ?? null);
            if ($actor) {
                $token['imageUrl'] = $actor->img_path ? url('storage/'.$actor->img_path) : ($actor->system['tokenImageUrl'] ?? null);
            }
            if (! in_array($token['actorId'] ?? null, $readable)) {
                $token['actorId'] = null;
            }
        }
        unset($token);

        return $state;
    }

    private function perceptionViewers(User $user): array
    {
        $actors = $this->campaign->actors()->where('owner_user_id', $user->id)->get()->keyBy('id');
        $viewers = [];
        foreach ($this->state['tokens'] ?? [] as $token) {
            if (($token['ownerUserId'] ?? null) !== $user->id) {
                continue;
            }
            $actor = $actors->get($token['actorId'] ?? null);
            if (! $actor) {
                continue;
            }
            $system = $actor->system;
            $wisdom = (int) data_get($system, 'abilities.wis.score', 10);
            $modifier = (int) floor(($wisdom - 10) / 2);
            $proficiency = (int) ($system['proficiencyBonus'] ?? 0);
            $skill = $system['skills']['perception'] ?? [];
            $skillBonus = ! empty($skill['expertise']) ? $proficiency * 2 : (! empty($skill['proficient']) ? $proficiency : 0);
            $viewers[] = [
                'actorId' => $actor->id,
                'x' => (float) $token['x'], 'y' => (float) $token['y'],
                'darkvision' => (float) data_get($system, 'senses.darkvision', 0),
                'passivePerception' => 10 + $modifier + $skillBonus,
            ];
        }

        return $viewers;
    }
}
