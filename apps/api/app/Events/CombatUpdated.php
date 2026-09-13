<?php

namespace App\Events;

use App\Models\Combat;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CombatUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $sceneId,
        public ?Combat $combat,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('scene.'.$this->sceneId)];
    }

    public function broadcastAs(): string
    {
        return 'CombatUpdated';
    }

    public function broadcastWith(): array
    {
        return ['sceneId' => $this->sceneId];
    }
}
