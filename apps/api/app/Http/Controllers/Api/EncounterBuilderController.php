<?php

namespace App\Http\Controllers\Api;

use App\Events\CombatUpdated;
use App\Events\SceneUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstantiateEncounterRequest;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\Combat;
use App\Models\CombatParticipant;
use App\Models\EncounterBuilderDraft;
use App\Models\Scene;
use App\Support\Dnd\MonsterActorFactory;
use App\Support\EncounterBuilderDifficulty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EncounterBuilderController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);

        return response()->json(['drafts' => EncounterBuilderDraft::where('campaign_id', $campaign->id)->latest('updated_at')->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $payload = $this->payload($request, $campaign);
        $draft = EncounterBuilderDraft::create(['campaign_id' => $campaign->id, ...$payload]);

        return response()->json(['draft' => $draft], 201);
    }

    public function update(Request $request, EncounterBuilderDraft $encounterBuilderDraft): JsonResponse
    {
        $this->gm($request, $encounterBuilderDraft->campaign);
        $encounterBuilderDraft->update($this->payload($request, $encounterBuilderDraft->campaign));

        return response()->json(['draft' => $encounterBuilderDraft->fresh()]);
    }

    public function destroy(Request $request, EncounterBuilderDraft $encounterBuilderDraft): JsonResponse
    {
        $this->gm($request, $encounterBuilderDraft->campaign);
        $encounterBuilderDraft->delete();

        return response()->json([], 204);
    }

    public function instantiate(InstantiateEncounterRequest $request, EncounterBuilderDraft $encounterBuilderDraft): JsonResponse
    {
        $this->gm($request, $encounterBuilderDraft->campaign);
        $data = $request->validated();

        return DB::transaction(function () use ($request, $encounterBuilderDraft, $data) {
            $draft = EncounterBuilderDraft::whereKey($encounterBuilderDraft->id)->lockForUpdate()->firstOrFail();
            $scene = Scene::whereKey($data['sceneId'])->where('campaign_id', $draft->campaign_id)->lockForUpdate()->firstOrFail();
            $metadata = $draft->metadata ?? [];
            $existing = data_get($metadata, 'instances.'.$data['requestId']);
            if (is_array($existing)) {
                $combat = isset($existing['combatId']) ? Combat::find($existing['combatId'])?->load('participants') : null;

                return response()->json([
                    'actors' => Actor::whereIn('id', $existing['actorIds'] ?? [])->get()->map->toPayload()->values(),
                    'tokenIds' => $existing['tokenIds'] ?? [],
                    'combat' => $combat?->toPayload(),
                    'state' => $scene->stateFor($request->user()),
                    'existing' => true,
                ]);
            }

            $catalogIds = collect($draft->creatures)->pluck('catalogEntryId')->unique()->values();
            $catalog = CatalogEntry::whereIn('id', $catalogIds)->where('kind', 'monsters')->where('active', true)->get()->keyBy('id');
            abort_unless($catalog->count() === $catalogIds->count(), 422, 'Uma criatura do rascunho não está mais disponível no catálogo.');

            $state = $scene->state;
            $state['tokens'] ??= [];
            $grid = max(8.0, (float) data_get($state, 'grid.size', 70));
            $originX = (float) ($data['x'] ?? max(0, (float) data_get($state, 'grid.offsetX', 0) + $grid));
            $originY = (float) ($data['y'] ?? max(0, (float) data_get($state, 'grid.offsetY', 0) + $grid));
            $occupied = collect($state['tokens'])->map(fn ($token) => round((float) ($token['x'] ?? 0), 3).':'.round((float) ($token['y'] ?? 0), 3))->flip()->all();
            $actors = [];
            $tokenIds = [];
            $sequence = 0;

            foreach ($draft->creatures as $choice) {
                $entry = $catalog->get($choice['catalogEntryId']);
                for ($i = 0; $i < (int) $choice['quantity']; $i++) {
                    $actor = Actor::create([
                        'campaign_id' => $draft->campaign_id,
                        'owner_user_id' => $request->user()->id,
                        'type' => 'monster',
                        'name' => mb_substr($choice['name'], 0, 105).' '.($i + 1),
                        'shared' => false,
                        'system' => MonsterActorFactory::fromCatalog($entry),
                    ]);
                    [$x, $y] = $this->safePosition($originX, $originY, $sequence++, $grid, $occupied);
                    $tokenId = 'encounter:'.$draft->id.':'.$data['requestId'].':'.$sequence;
                    $state['tokens'][] = [
                        'id' => $tokenId,
                        'name' => $actor->name,
                        'actorId' => $actor->id,
                        'ownerUserId' => $request->user()->id,
                        'size' => MonsterActorFactory::tokenSize($entry),
                        'hidden' => (bool) ($data['hidden'] ?? false),
                        'x' => $x,
                        'y' => $y,
                    ];
                    $actors[] = $actor;
                    $tokenIds[] = $tokenId;
                }
            }

            $scene->state = $state;
            $scene->save();

            $combat = null;
            if ($data['startCombat'] ?? false) {
                Combat::where('scene_id', $scene->id)->where('is_active', true)->delete();
                $combat = Combat::create(['scene_id' => $scene->id, 'round' => 1, 'turn' => 0, 'is_active' => true]);
            } elseif ($data['addToCombat'] ?? false) {
                $combat = Combat::where('scene_id', $scene->id)->where('is_active', true)->latest('id')->first();
                abort_unless($combat !== null, 422, 'Inicie um combate na cena antes de adicionar o encontro.');
            }
            if ($combat) {
                $sort = $combat->participants()->count();
                foreach ($actors as $index => $actor) {
                    CombatParticipant::create([
                        'combat_id' => $combat->id,
                        'actor_id' => $actor->id,
                        'token_id' => $tokenIds[$index],
                        'name' => $actor->name,
                        'img_path' => $actor->img_path,
                        'sort' => $sort++,
                    ]);
                }
                $combat->load('participants');
            }

            $metadata['instances'][$data['requestId']] = [
                'sceneId' => $scene->id,
                'actorIds' => array_map(fn (Actor $actor) => $actor->id, $actors),
                'tokenIds' => $tokenIds,
                'combatId' => $combat?->id,
                'createdAt' => now()->toIso8601String(),
            ];
            $draft->metadata = $metadata;
            $draft->save();

            DB::afterCommit(function () use ($scene, $combat) {
                broadcast(new SceneUpdated($scene, 'token'));
                if ($combat) {
                    broadcast(new CombatUpdated($scene->id, $combat));
                }
            });

            return response()->json([
                'actors' => collect($actors)->map->toPayload()->values(),
                'tokenIds' => $tokenIds,
                'combat' => $combat?->toPayload(),
                'state' => $scene->stateFor($request->user()),
                'existing' => false,
            ], 201);
        });
    }

    private function safePosition(float $originX, float $originY, int $index, float $grid, array &$occupied): array
    {
        for ($attempt = 0; $attempt < 400; $attempt++) {
            $slot = $index + $attempt;
            $column = $slot % 10;
            $row = intdiv($slot, 10);
            $x = max(0, round($originX + $column * $grid, 3));
            $y = max(0, round($originY + $row * $grid, 3));
            $key = $x.':'.$y;
            if (! isset($occupied[$key])) {
                $occupied[$key] = true;

                return [$x, $y];
            }
        }
        abort(422, 'Não foi possível encontrar espaço livre para os tokens do encontro.');
    }

    private function payload(Request $request, Campaign $campaign): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'party' => ['nullable', 'array', 'max:20'], 'party.*.level' => ['required_with:party', 'integer', 'between:1,20'],
            'creatures' => ['required', 'array', 'min:1', 'max:40'],
            'creatures.*.catalogEntryId' => ['required', 'integer', Rule::exists('catalog_entries', 'id')->where('kind', 'monsters')],
            'creatures.*.quantity' => ['required', 'integer', 'between:1,50'], 'metadata' => ['nullable', 'array'],
        ]);
        $ids = collect($data['creatures'])->pluck('catalogEntryId')->unique()->all();
        $catalog = CatalogEntry::whereIn('id', $ids)->where('kind', 'monsters')->get()->keyBy('id');
        $creatures = array_map(function ($choice) use ($catalog) {
            $entry = $catalog->get($choice['catalogEntryId']);
            abort_unless($entry !== null, 422, 'Use apenas criaturas válidas do catálogo.');

            return EncounterBuilderDifficulty::creature($entry, (int) $choice['quantity']);
        }, $data['creatures']);
        $party = $data['party'] ?? [];

        return [
            'name' => $data['name'], 'party' => $party, 'creatures' => $creatures,
            'difficulty' => EncounterBuilderDifficulty::summarize($creatures, $party, $campaign->ruleset), 'metadata' => $data['metadata'] ?? [],
        ];
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->canManage($request->user()), 403, 'Apenas o GM pode preparar encontros.');
    }
}
