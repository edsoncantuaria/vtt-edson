<?php

namespace App\Support;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\CampaignMacro;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MacroExecutor
{
    public function __construct(private readonly DiceRoller $dice) {}

    /** @return array{state:array<string,mixed>,messages:list<array<string,mixed>>} */
    public function execute(CampaignMacro $macro, Scene $scene, User $user, ?int $actorId, array $targetActorIds, ?array $point): array
    {
        abort_unless((int) $macro->campaign_id === (int) $scene->campaign_id, 422);
        $campaign = $scene->campaign;
        $actor = $actorId ? Actor::where('campaign_id', $campaign->id)->findOrFail($actorId) : null;
        if ($actor && ! $campaign->canManage($user) && ! $actor->isOwnedBy($user)) {
            abort(403, 'A macro só pode usar sua própria ficha.');
        }
        $targets = Actor::where('campaign_id', $campaign->id)->whereIn('id', array_values(array_unique(array_map('intval', $targetActorIds))))->get();
        if (! $campaign->canManage($user)) {
            $targets = $targets->filter(fn (Actor $target) => $target->isOwnedBy($user));
        }

        $messages = [];
        $state = $scene->state;
        foreach ($macro->commands as $command) {
            $type = $command['type'] ?? null;
            if ($type === 'roll') {
                $formula = mb_substr((string) ($command['formula'] ?? 'd20'), 0, 80);
                try {
                    $roll = $this->dice->roll($formula);
                } catch (InvalidArgumentException $error) {
                    abort(422, $error->getMessage());
                }
                $message = [
                    'id' => (string) Str::uuid(), 'userId' => $user->id, 'userName' => $user->name, 'type' => 'roll',
                    'formula' => $roll['formula'], 'total' => $roll['total'], 'detail' => $roll['detail'],
                    'critical' => $roll['critical'], 'fumble' => $roll['fumble'],
                    'label' => mb_substr((string) ($command['label'] ?? $macro->name), 0, 80), 'createdAt' => now()->toIso8601String(),
                ];
                $state['chat'][] = $message;
                $messages[] = $message;
            } elseif ($type === 'chat') {
                $text = trim(mb_substr((string) ($command['text'] ?? ''), 0, 1000));
                if ($text === '') {
                    continue;
                }
                $message = [
                    'id' => (string) Str::uuid(), 'userId' => $user->id, 'userName' => $user->name, 'type' => 'text',
                    'text' => $text, 'createdAt' => now()->toIso8601String(),
                ];
                $state['chat'][] = $message;
                $messages[] = $message;
            } elseif ($type === 'effect') {
                $selected = ($command['target'] ?? 'self') === 'targets' ? $targets : collect($actor ? [$actor] : []);
                foreach ($selected as $target) {
                    ActiveEffect::create([
                        'actor_id' => $target->id,
                        'name' => mb_substr((string) ($command['name'] ?? $macro->name), 0, 160),
                        'duration' => is_array($command['duration'] ?? null) ? $command['duration'] : ['unit' => 'rounds', 'remaining' => 1],
                        'modifiers' => is_array($command['modifiers'] ?? null) ? array_slice($command['modifiers'], 0, 30) : [],
                        'conditions' => is_array($command['conditions'] ?? null) ? array_slice($command['conditions'], 0, 20) : [],
                        'metadata' => ['macroId' => $macro->id, 'createdBy' => $user->id],
                        'active' => true,
                    ]);
                }
            } elseif ($type === 'resource' && $actor) {
                $system = $actor->system;
                $resourceId = (string) ($command['resourceId'] ?? '');
                $index = collect($system['resources'] ?? [])->search(fn ($resource) => ($resource['id'] ?? null) === $resourceId);
                if ($index !== false) {
                    $delta = max(-1000, min(1000, (int) ($command['usedDelta'] ?? 0)));
                    $system['resources'][$index]['used'] = max(0, min((int) $system['resources'][$index]['max'], (int) $system['resources'][$index]['used'] + $delta));
                    $actor->system = $system;
                    $actor->save();
                }
            } elseif ($type === 'ping' && $point) {
                $state['pings'] ??= [];
                $state['pings'][] = [
                    'id' => (string) Str::uuid(), 'x' => (float) $point['x'], 'y' => (float) $point['y'],
                    'label' => mb_substr((string) ($command['label'] ?? $macro->name), 0, 80),
                    'userName' => $user->name, 'createdAt' => now()->toIso8601String(),
                ];
                $state['pings'] = array_slice($state['pings'], -20);
            }
        }
        $state['chat'] = array_slice($state['chat'] ?? [], -200);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'macro'));

        return ['state' => $scene->stateFor($user), 'messages' => $messages];
    }
}
