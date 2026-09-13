<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $scene_id
 * @property int $user_id
 * @property string $role
 * @property-read Scene $scene
 * @property-read User $user
 */
class SceneMember extends Model
{
    protected $fillable = ['scene_id', 'user_id', 'role'];

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGm(): bool
    {
        return in_array($this->role, ['gm', 'assistant'], true);
    }
}
