<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMember extends Model
{
    protected $fillable = ['campaign_id', 'user_id', 'role', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canManage(): bool
    {
        return in_array($this->role, ['gm', 'assistant'], true);
    }
}
