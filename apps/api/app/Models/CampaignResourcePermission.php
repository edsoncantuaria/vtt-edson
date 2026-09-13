<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignResourcePermission extends Model
{
    protected $fillable = ['campaign_id', 'user_id', 'resource_type', 'resource_id', 'permission'];

    public static function permits(Campaign $campaign, User $user, string $type, int $id, string $needed = 'view'): bool
    {
        if ($campaign->canManage($user)) {
            return true;
        }
        $grant = static::query()->where([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'resource_type' => $type,
            'resource_id' => $id,
        ])->value('permission');
        $rank = ['view' => 1, 'edit' => 2, 'manage' => 3];

        return ($rank[$grant] ?? 0) >= ($rank[$needed] ?? 1);
    }
}
