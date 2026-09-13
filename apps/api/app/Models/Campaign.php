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

    /** GM é o dono da campanha; jogador é quem tem SceneMember em alguma cena dela. */
    public function roleFor(User $user): ?string
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return 'gm';
        }

        $isPlayer = SceneMember::query()
            ->whereIn('scene_id', $this->scenes()->pluck('id'))
            ->where('user_id', $user->id)
            ->exists();

        return $isPlayer ? 'player' : null;
    }

    public function isMember(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }
}
