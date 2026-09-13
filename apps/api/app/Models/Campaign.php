<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $ruleset
 * @property array<string, mixed> $house_rules
 * @property list<string>|null $catalog_sources
 * @property-read User $owner
 * @property-read Room|null $room
 * @property-read Collection<int, Scene> $scenes
 * @property-read Collection<int, Actor> $actors
 * @property-read Collection<int, Journal> $journals
 * @property-read Collection<int, CampaignMember> $members
 */
class Campaign extends Model
{
    protected function casts(): array
    {
        return ['house_rules' => 'array', 'catalog_sources' => 'array'];
    }

    protected $fillable = ['owner_id', 'name', 'ruleset', 'catalog_sources'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasOne<Room, $this> */
    public function room(): HasOne
    {
        return $this->hasOne(Room::class);
    }

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    /** @return HasMany<Actor, $this> */
    public function actors(): HasMany
    {
        return $this->hasMany(Actor::class);
    }

    /** @return HasMany<Journal, $this> */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /** @return HasMany<CampaignMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }

    /** GM é o dono da campanha; jogador é quem tem SceneMember em alguma cena dela. */
    public function roleFor(User $user): ?string
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return 'gm';
        }

        $membership = $this->members()->where('user_id', $user->id)->first();
        if ($membership) {
            return $membership->role;
        }

        $isPlayer = SceneMember::query()
            ->whereIn('scene_id', $this->scenes()->pluck('id'))
            ->where('user_id', $user->id)
            ->exists();

        return $isPlayer ? 'player' : null;
    }

    public function canManage(User $user): bool
    {
        return in_array($this->roleFor($user), ['gm', 'assistant'], true);
    }

    public function can(User $user, string $capability): bool
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return true;
        }
        $member = $this->members()->where('user_id', $user->id)->first();
        if (! $member) {
            return false;
        }
        if ($member->role === 'assistant') {
            return $capability !== 'campaign-owner';
        }
        $permissions = $member->permissions ?? [];

        return in_array($capability, $permissions, true);
    }

    public function isMember(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }
}
