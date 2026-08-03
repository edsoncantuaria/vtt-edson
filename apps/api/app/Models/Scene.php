<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    protected $fillable = [
        'campaign_id',
        'name',
        'background_path',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(SceneMember::class);
    }

    public function memberFor(User $user): ?SceneMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }
}
