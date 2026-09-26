<?php

namespace App\Support\Dnd;

use App\Models\Actor;
use Illuminate\Support\Facades\DB;

final class ResourceAudit
{
    public static function snapshot(Actor $actor, ?array $system = null): array
    {
        return ResourcePool::pools($system ?? $actor->system, $actor->campaign->ruleset, $actor->id, $actor->documents()->get());
    }

    public static function record(Actor $actor, string $event, array $before, array $after, ?int $userId,
        ?string $requestId = null, ?string $requestHash = null, ?string $resourceId = null, ?string $reason = null): void
    {
        DB::table('actor_resource_events')->insert([
            'actor_id' => $actor->id, 'user_id' => $userId, 'request_id' => $requestId, 'request_hash' => $requestHash,
            'event' => $event, 'resource_id' => $resourceId,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
