<?php

namespace App\Support\Dnd;

use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CatalogEntry;
use Illuminate\Support\Str;

final class ActorDocumentService
{
    public function syncFromLegacy(Actor $actor): void
    {
        $system = $actor->system;
        $collections = [
            'item' => $system['inventory'] ?? [],
            'spell' => $system['spells']['known'] ?? [],
            'feature' => $system['features'] ?? [],
        ];
        foreach ($collections as $kind => $rows) {
            $documents = $actor->documents()->where('kind', $kind)->get();
            $matched = [];
            foreach ($rows as $sort => $row) {
                if (! is_array($row) || empty($row['name'])) {
                    continue;
                }
                $document = $documents->first(function (ActorDocument $candidate) use ($row) {
                    $candidateId = (string) data_get($candidate->data, 'id', '');
                    $rowId = (string) ($row['id'] ?? '');
                    if ($candidateId !== '' && $rowId !== '' && $candidateId === $rowId) {
                        return true;
                    }

                    return ($candidate->slug ?? '') !== '' && $candidate->slug === ($row['slug'] ?? null) && $candidate->name === $row['name'];
                });
                if (! $document) {
                    $document = new ActorDocument(['actor_id' => $actor->id, 'kind' => $kind]);
                }
                $document->name = mb_substr((string) $row['name'], 0, 160);
                $document->slug = $row['slug'] ?? $document->slug;
                $document->source = $row['source'] ?? $document->source;
                $document->data = $row;
                $document->quantity = max(1, (int) ($row['quantity'] ?? $document->quantity ?? 1));
                $document->equipped = (bool) ($row['equipped'] ?? $document->equipped ?? false);
                $document->prepared = (bool) ($row['prepared'] ?? $document->prepared ?? false);
                $document->sort = (int) $sort;
                $document->save();
                $matched[] = $document->id;
            }
            $actor->documents()->where('kind', $kind)->whereNotIn('id', $matched ?: [0])->delete();
        }
    }

    public function fromCatalog(Actor $actor, CatalogEntry $entry, string $kind): ActorDocument
    {
        $data = $entry->data ?? [];
        $automation = data_get($data, 'integration.automation', []);
        $payload = match ($kind) {
            'spell' => [
                'id' => (string) Str::uuid(), 'slug' => $entry->slug, 'name' => $entry->name,
                'level' => $entry->level ?? 0, 'prepared' => false,
                'description' => (string) ($data['description'] ?? ''),
            ],
            'item' => [
                'id' => (string) Str::uuid(), 'slug' => $entry->slug, 'name' => $entry->name,
                'quantity' => 1, 'equipped' => false,
                'description' => (string) ($data['description'] ?? $data['effect'] ?? ''),
                'damage' => is_string($data['damage'] ?? null) ? $data['damage'] : null,
            ],
            default => [
                'id' => (string) Str::uuid(), 'slug' => $entry->slug, 'name' => $entry->name,
                'source' => $entry->source, 'description' => (string) ($data['description'] ?? ''),
            ],
        };
        if (is_array($automation)) {
            $payload['automation'] = $automation;
        }

        $charges = is_array($automation['charges'] ?? null) ? $automation['charges'] : null;
        $document = ActorDocument::create([
            'actor_id' => $actor->id,
            'catalog_entry_id' => $entry->id,
            'kind' => $kind,
            'name' => $entry->name,
            'slug' => $entry->slug,
            'source' => $entry->source,
            'data' => $payload,
            'overrides' => [],
            'quantity' => 1,
            'prepared' => false,
            'equipped' => false,
            'attuned' => false,
            'charges' => $charges,
            'sort' => $actor->documents()->where('kind', $kind)->count(),
        ]);
        $this->syncLegacy($actor);

        return $document;
    }

    public function syncLegacy(Actor $actor): void
    {
        $actor->refresh();
        $system = $actor->system;
        $documents = $actor->documents()->orderBy('sort')->orderBy('id')->get();
        $system['inventory'] = $documents->where('kind', 'item')->map(function (ActorDocument $document) {
            $data = array_replace($document->data ?? [], $document->overrides ?? []);
            unset($data['automation']);
            $data['id'] ??= (string) $document->id;
            $data['documentId'] = $document->id;
            $data['name'] = $document->name;
            $data['slug'] = $document->slug;
            $data['quantity'] = $document->quantity;
            $data['equipped'] = (bool) $document->equipped;

            return $data;
        })->values()->all();
        $system['spells']['known'] = $documents->where('kind', 'spell')->map(function (ActorDocument $document) {
            $data = array_replace($document->data ?? [], $document->overrides ?? []);
            unset($data['automation']);
            $data['id'] ??= (string) $document->id;
            $data['documentId'] = $document->id;
            $data['name'] = $document->name;
            $data['slug'] = $document->slug;
            $data['prepared'] = (bool) $document->prepared;

            return $data;
        })->values()->all();
        $system['features'] = $documents->where('kind', 'feature')->map(function (ActorDocument $document) {
            $data = array_replace($document->data ?? [], $document->overrides ?? []);
            unset($data['automation']);
            $data['id'] ??= (string) $document->id;
            $data['documentId'] = $document->id;
            $data['name'] = $document->name;
            $data['source'] = $document->source;

            return $data;
        })->values()->all();

        // Manual/monster actions remain in the actor. Document actions are rebuilt
        // from canonical automation so deleting/updating a document cannot leave stale actions.
        $actions = array_values(array_filter($system['actions'] ?? [], fn ($action) => ! is_array($action) || ! str_starts_with((string) ($action['id'] ?? ''), 'document:')));
        foreach ($documents as $document) {
            $automation = data_get($document->data, 'automation', []);
            foreach (is_array($automation['actions'] ?? null) ? $automation['actions'] : [] as $index => $action) {
                if (! is_array($action) || empty($action['name'])) {
                    continue;
                }
                $action['id'] = 'document:'.$document->id.':'.$index;
                $action['documentId'] = $document->id;
                if ($document->charges && ! isset($action['chargeCost'])) {
                    $action['chargeCost'] = 1;
                }
                $actions[] = $action;
            }
        }
        $system['actions'] = $actions;
        $actor->system = $system;
        $actor->save();
    }

    public function resetCharges(Actor $actor, string $rest): void
    {
        $actor->documents()->whereNotNull('charges')->get()->each(function (ActorDocument $document) use ($rest) {
            $charges = $document->charges ?? [];
            if (($charges['reset'] ?? 'manual') === $rest && isset($charges['max'])) {
                $charges['value'] = (int) $charges['max'];
                $document->charges = $charges;
                $document->save();
            }
        });
    }
}
