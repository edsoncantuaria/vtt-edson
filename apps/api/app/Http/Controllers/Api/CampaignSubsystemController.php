<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CampaignSubsystem;
use App\Models\CatalogEntry;
use App\Support\Dnd\ActorDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CampaignSubsystemController extends Controller
{
    private const KINDS = ['bastions', 'vehicles', 'decks', 'recipes', 'psionics', 'rewards', 'deities', 'languages', 'hazards', 'objects', 'cults'];

    public function __construct(private readonly ActorDocumentService $documents) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        return response()->json(['subsystems' => CampaignSubsystem::where('campaign_id', $campaign->id)->where('active', true)->with('catalogEntry')->orderBy('kind')->orderBy('name')->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->manage($request, $campaign);
        $data = $request->validate(['catalogEntryId' => ['required', 'integer', 'exists:catalog_entries,id']]);
        $entry = CatalogEntry::findOrFail($data['catalogEntryId']);
        abort_unless(in_array($entry->kind, self::KINDS, true), 422, 'Este verbete não possui subsistema dedicado.');
        abort_unless($entry->active, 422);
        $subsystem = CampaignSubsystem::create([
            'campaign_id' => $campaign->id, 'catalog_entry_id' => $entry->id, 'kind' => $entry->kind,
            'name' => $entry->name, 'state' => $this->initialState($entry), 'metadata' => ['source' => $entry->source, 'edition' => $entry->edition],
        ]);

        return response()->json(['subsystem' => $subsystem->load('catalogEntry')], 201);
    }

    public function action(Request $request, CampaignSubsystem $campaignSubsystem): JsonResponse
    {
        $campaign = $campaignSubsystem->campaign;
        abort_unless($campaign->isMember($request->user()), 403);
        abort_if($campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        $data = $request->validate([
            'action' => ['required', 'string', 'max:40'], 'value' => ['nullable'], 'key' => ['nullable', 'string', 'max:80'],
            'actorId' => ['nullable', 'integer', 'exists:actors,id'],
        ]);
        $action = $data['action'];
        $state = $campaignSubsystem->state ?? [];
        $managerOnly = ! in_array($action, ['deck.draw'], true);
        if ($managerOnly) {
            $this->manage($request, $campaign);
        }

        if ($campaignSubsystem->kind === 'decks') {
            $state = $this->deckAction($state, $action);
        } elseif ($campaignSubsystem->kind === 'vehicles') {
            $state = $this->vehicleAction($state, $action, $data);
        } elseif ($campaignSubsystem->kind === 'bastions') {
            $state = $this->bastionAction($state, $action, $data);
        } elseif ($campaignSubsystem->kind === 'recipes') {
            $state = $this->recipeAction($state, $action, $data);
        } elseif ($action === 'toggle') {
            $state['enabled'] = ! ($state['enabled'] ?? true);
        } elseif ($action === 'apply-to-actor') {
            $this->applyToActor($request, $campaignSubsystem, (int) ($data['actorId'] ?? 0));
        } else {
            abort(422, 'Ação não suportada por este subsistema.');
        }
        $campaignSubsystem->state = $state;
        $campaignSubsystem->save();

        return response()->json(['subsystem' => $campaignSubsystem->fresh()->load('catalogEntry')]);
    }

    public function destroy(Request $request, CampaignSubsystem $campaignSubsystem): JsonResponse
    {
        $this->manage($request, $campaignSubsystem->campaign);
        $campaignSubsystem->delete();

        return response()->json(['ok' => true]);
    }

    private function initialState(CatalogEntry $entry): array
    {
        $raw = $entry->data['raw'] ?? [];
        $integrated = data_get($entry->data, 'integration.automation.subsystem.state');
        if (is_array($integrated)) {
            if ($entry->kind === 'decks') {
                return $this->deckState(['cards' => $integrated['cards'] ?? []]);
            }
            if ($entry->kind === 'vehicles') {
                $rawHp = $integrated['hp'] ?? 100;
                $vehicleHp = is_array($rawHp) ? (int) ($rawHp['average'] ?? $rawHp['value'] ?? 100) : (is_numeric($rawHp) ? (int) $rawHp : 100);
                $rawAc = $integrated['ac'] ?? 10;
                $vehicleAc = is_array($rawAc) ? (int) (is_array($rawAc[0] ?? null) ? ($rawAc[0]['ac'] ?? 10) : ($rawAc[0] ?? 10)) : (is_numeric($rawAc) ? (int) $rawAc : 10);

                return ['hp' => $vehicleHp, 'maxHp' => $vehicleHp, 'ac' => $vehicleAc, 'speed' => $integrated['speed'] ?? null, 'crew' => []];
            }
            if ($entry->kind === 'bastions') {
                return ['turn' => 0, 'resources' => [], 'facilities' => [$entry->name], 'upstream' => $integrated];
            }
            if ($entry->kind === 'recipes') {
                return ['progress' => 0, 'required' => 1, 'completed' => false, 'upstream' => $integrated];
            }
        }
        $rawHp = $raw['hp'] ?? 100;
        $vehicleHp = is_array($rawHp)
            ? (int) ($rawHp['average'] ?? $rawHp['value'] ?? 100)
            : (is_numeric($rawHp) ? (int) $rawHp : 100);
        $rawAc = $raw['ac'] ?? 10;
        $vehicleAc = is_array($rawAc)
            ? (int) (is_array($rawAc[0] ?? null) ? (($rawAc[0]['ac'] ?? 10)) : ($rawAc[0] ?? 10))
            : (is_numeric($rawAc) ? (int) $rawAc : 10);

        return match ($entry->kind) {
            'decks' => $this->deckState($raw),
            'vehicles' => [
                'hp' => $vehicleHp,
                'maxHp' => $vehicleHp,
                'ac' => $vehicleAc,
                'speed' => data_get($raw, 'speed', null), 'crew' => [],
            ],
            'bastions' => ['turn' => 0, 'resources' => [], 'facilities' => [$entry->name]],
            'recipes' => ['progress' => 0, 'required' => max(1, (int) data_get($raw, 'craftingTime', 1)), 'completed' => false],
            'psionics' => ['uses' => 0, 'enabled' => true],
            default => ['enabled' => true, 'notes' => ''],
        };
    }

    private function deckState(array $raw): array
    {
        $cards = [];
        foreach ($raw['cards'] ?? $raw['card'] ?? [] as $card) {
            if (is_string($card)) {
                $cards[] = $card;
            } elseif (is_array($card)) {
                $cards[] = (string) ($card['name'] ?? $card['card'] ?? json_encode($card));
            }
        }
        if (! $cards) {
            $cards = [(string) ($raw['name'] ?? 'Carta')];
        }
        $draw = $cards;
        shuffle($draw);

        return ['original' => $cards, 'draw' => $draw, 'discard' => [], 'lastDraw' => null];
    }

    private function deckAction(array $state, string $action): array
    {
        if ($action === 'deck.shuffle') {
            $state['draw'] = [...($state['draw'] ?? []), ...($state['discard'] ?? [])];
            $state['discard'] = [];
            shuffle($state['draw']);
        } elseif ($action === 'deck.draw') {
            abort_unless(count($state['draw'] ?? []) > 0, 422, 'O baralho está vazio. Embaralhe o descarte ou reinicie.');
            $card = array_shift($state['draw']);
            $state['discard'][] = $card;
            $state['lastDraw'] = $card;
        } elseif ($action === 'deck.reset') {
            $state['draw'] = $state['original'] ?? [];
            shuffle($state['draw']);
            $state['discard'] = [];
            $state['lastDraw'] = null;
        } else {
            abort(422, 'Ação de baralho inválida.');
        }

        return $state;
    }

    private function vehicleAction(array $state, string $action, array $data): array
    {
        $value = max(0, (int) ($data['value'] ?? 0));
        if ($action === 'vehicle.damage') {
            $state['hp'] = max(0, (int) ($state['hp'] ?? 0) - $value);
        } elseif ($action === 'vehicle.heal') {
            $state['hp'] = min((int) ($state['maxHp'] ?? 0), (int) ($state['hp'] ?? 0) + $value);
        } elseif ($action === 'vehicle.crew') {
            $state['crew'] = array_values(array_filter(array_map('trim', explode(',', (string) ($data['value'] ?? '')))));
        } else {
            abort(422, 'Ação de veículo inválida.');
        }

        return $state;
    }

    private function bastionAction(array $state, string $action, array $data): array
    {
        if ($action === 'bastion.advance') {
            $state['turn'] = (int) ($state['turn'] ?? 0) + 1;
        } elseif ($action === 'bastion.resource') {
            $key = Str::slug((string) ($data['key'] ?? 'recurso'));
            abort_if($key === '', 422);
            $state['resources'][$key] = (int) ($data['value'] ?? 0);
        } else {
            abort(422, 'Ação de bastião inválida.');
        }

        return $state;
    }

    private function recipeAction(array $state, string $action, array $data): array
    {
        if ($action === 'recipe.progress') {
            $state['progress'] = max(0, (int) ($state['progress'] ?? 0) + (int) ($data['value'] ?? 1));
            $state['completed'] = $state['progress'] >= (int) ($state['required'] ?? 1);
        } elseif ($action === 'recipe.reset') {
            $state['progress'] = 0;
            $state['completed'] = false;
        } else {
            abort(422, 'Ação de receita inválida.');
        }

        return $state;
    }

    private function applyToActor(Request $request, CampaignSubsystem $subsystem, int $actorId): void
    {
        $actor = Actor::where('campaign_id', $subsystem->campaign_id)->findOrFail($actorId);
        abort_unless($subsystem->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()), 403);
        $entry = $subsystem->catalogEntry;
        if ($entry === null) {
            abort(422, 'A referência do compêndio não está mais disponível.');
        }
        $kind = $subsystem->kind === 'objects' ? 'item' : 'feature';
        $this->documents->fromCatalog($actor, $entry, $kind);
    }

    private function manage(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->canManage($request->user()) || $campaign->can($request->user(), 'subsystems.manage'), 403);
    }
}
