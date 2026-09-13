<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CampaignResourcePermission;
use App\Models\CatalogEntry;
use App\Support\Dnd\ActorDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ActorDocumentController extends Controller
{
    public function __construct(private readonly ActorDocumentService $documents) {}

    public function index(Request $request, Actor $actor): JsonResponse
    {
        $this->requireView($request, $actor);

        return response()->json(['documents' => $actor->documents()->with('effects')->get()]);
    }

    public function store(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEdit($request, $actor);
        $data = $request->validate([
            'catalogEntryId' => ['nullable', 'integer', 'exists:catalog_entries,id'],
            'kind' => ['required', Rule::in(['item', 'spell', 'feature'])],
            'name' => ['required_without:catalogEntryId', 'string', 'max:160'],
            'source' => ['nullable', 'string', 'max:80'],
            'data' => ['nullable', 'array'],
            'charges' => ['nullable', 'array:value,max,reset,recoveryFormula'],
            'charges.value' => ['required_with:charges', 'integer', 'min:0', 'max:10000'],
            'charges.max' => ['required_with:charges', 'integer', 'min:0', 'max:10000'],
            'charges.reset' => ['required_with:charges', Rule::in(['short', 'long', 'dawn', 'manual'])],
            'charges.recoveryFormula' => ['nullable', 'string', 'regex:/^\d*d\d+(?:[+-]\d+)?$/i'],
        ]);
        if (isset($data['catalogEntryId'])) {
            $entry = CatalogEntry::query()->where('active', true)->findOrFail($data['catalogEntryId']);
            $expectedKind = match ($entry->kind) {
                'items', 'magic-variants' => 'item', 'spells' => 'spell', default => 'feature',
            };
            abort_unless($expectedKind === $data['kind'], 422, 'O tipo escolhido não corresponde ao verbete do compêndio.');
            $document = $this->documents->fromCatalog($actor, $entry, $data['kind']);
        } else {
            $document = ActorDocument::create([
                'actor_id' => $actor->id,
                'kind' => $data['kind'],
                'name' => $data['name'],
                'source' => $data['source'] ?? null,
                'data' => $data['data'] ?? ['name' => $data['name']],
                'overrides' => [],
                'charges' => $data['charges'] ?? null,
                'sort' => $actor->documents()->where('kind', $data['kind'])->count(),
            ]);
            $this->documents->syncLegacy($actor);
        }

        return response()->json(['document' => $document->fresh(), 'actor' => $actor->fresh()->toPayload()], 201);
    }

    public function update(Request $request, ActorDocument $actorDocument): JsonResponse
    {
        $actor = $actorDocument->actor;
        $this->requireEdit($request, $actor);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'overrides' => ['sometimes', 'array'],
            'quantity' => ['sometimes', 'integer', 'between:1,10000'],
            'equipped' => ['sometimes', 'boolean'],
            'prepared' => ['sometimes', 'boolean'],
            'attuned' => ['sometimes', 'boolean'],
            'charges' => ['sometimes', 'nullable', 'array:value,max,reset,recoveryFormula'],
            'charges.value' => ['required_with:charges', 'integer', 'min:0', 'max:10000'],
            'charges.max' => ['required_with:charges', 'integer', 'min:0', 'max:10000'],
            'charges.reset' => ['required_with:charges', Rule::in(['short', 'long', 'dawn', 'manual'])],
            'charges.recoveryFormula' => ['nullable', 'string', 'regex:/^\d*d\d+(?:[+-]\d+)?$/i'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
        if (($data['attuned'] ?? false) && ! $actorDocument->attuned) {
            abort_unless($actor->documents()->where('attuned', true)->count() < 3, 422, 'A ficha já possui três itens sintonizados.');
        }
        $actorDocument->update($data);
        $this->documents->syncLegacy($actor);

        return response()->json(['document' => $actorDocument->fresh(), 'actor' => $actor->fresh()->toPayload()]);
    }

    public function destroy(Request $request, ActorDocument $actorDocument): JsonResponse
    {
        $actor = $actorDocument->actor;
        $this->requireEdit($request, $actor);
        $actorDocument->delete();
        $this->documents->syncLegacy($actor);

        return response()->json(['actor' => $actor->fresh()->toPayload()]);
    }

    private function requireView(Request $request, Actor $actor): void
    {
        $campaign = $actor->campaign;
        abort_unless($campaign->isMember($request->user()), 403);
        abort_unless($campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()) || $actor->shared
            || CampaignResourcePermission::permits($campaign, $request->user(), 'actor', $actor->id), 403);
    }

    private function requireEdit(Request $request, Actor $actor): void
    {
        abort_unless($actor->campaign->roleFor($request->user()) !== 'observer'
            && ($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
    }
}
