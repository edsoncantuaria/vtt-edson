<?php

namespace App\Events;

use App\Models\Scene;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SceneUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Scene $scene,
        public string $reason = 'update',
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('scene.'.$this->scene->id)];
    }

    public function broadcastAs(): string
    {
        return 'SceneUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'sceneId' => $this->scene->id,
            'reason' => $this->reason,
            'state' => $this->scene->state,
            'backgroundUrl' => $this->scene->background_path
                ? url('storage/'.$this->scene->background_path)
                : null,
            'name' => $this->scene->name,
        ];
    }
}
