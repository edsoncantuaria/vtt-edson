<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $owner_user_id
 * @property string $type
 * @property string $name
 * @property string|null $img_path
 * @property array<string, mixed> $system
 * @property bool $shared
 * @property int $revision
 * @property-read Campaign $campaign
 * @property-read User|null $owner
 */
class Actor extends Model
{
    protected $fillable = [
        'campaign_id',
        'owner_user_id',
        'type',
        'name',
        'img_path',
        'system',
        'shared',
    ];

    protected function casts(): array
    {
        return [
            'system' => 'array',
            'shared' => 'boolean',
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Actor $actor) {
            if ($actor->isDirty('system')) {
                $system = $actor->system;
                $conditions = array_map(fn ($condition) => strtolower(Str::ascii((string) $condition)), $system['conditions'] ?? []);
                if (($system['hp']['value'] ?? 1) <= 0 || array_intersect($conditions, ['incapacitated', 'unconscious', 'stunned', 'paralyzed', 'petrified', 'incapacitado', 'inconsciente', 'atordoado', 'paralisado', 'petrificado'])) {
                    $system['concentration'] = null;
                }
                $actor->system = $system;
                if ($actor->exists) {
                    $actor->revision = ($actor->revision ?? 0) + 1;
                }
            }
        });
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->owner_user_id === (int) $user->id;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $system = $this->system;
        // Spell levels form a JSON dictionary, including when no slots exist.
        $system['spells']['slots'] = (object) ($system['spells']['slots'] ?? []);

        return [
            'id' => $this->id,
            'revision' => $this->revision ?? 0,
            'shared' => (bool) $this->shared,
            'campaignId' => $this->campaign_id,
            'ownerUserId' => $this->owner_user_id,
            'type' => $this->type,
            'name' => $this->name,
            'imgPath' => $this->img_path,
            'imgUrl' => $this->img_path ? url('storage/'.$this->img_path) : null,
            'system' => $system,
        ];
    }
}
