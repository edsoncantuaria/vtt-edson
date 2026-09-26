<?php

namespace App\Support\Dnd;

use App\Models\ActiveEffect;
use Illuminate\Support\Facades\DB;

final class EffectAudit
{
    public static function record(ActiveEffect $effect, string $event, ?array $before = null, ?int $userId = null, ?string $reason = null): void
    {
        DB::table('active_effect_events')->insert([
            'actor_id' => $effect->actor_id, 'effect_id' => $effect->id, 'user_id' => $userId,
            'event' => $event, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after' => in_array($event, ['removed'], true) ? null : json_encode($effect->fresh()?->toArray(), JSON_THROW_ON_ERROR),
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
