<?php

namespace App\Support;

use App\Game\Dice\DiceRoller;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\EncounterBuilderDraft;
use App\Models\LootResult;
use App\Models\RollTable;
use App\Models\RollTableRoll;
use Illuminate\Support\Str;

/**
 * Materializes the stable data.integration schema produced by build-compendium.py.
 * 5etools raw JSON is retained for provenance, but campaign runtime code consumes
 * this normalized contract so upstream format changes stay isolated at import time.
 */
final class FiveToolsIntegrationService
{
    public function __construct(private readonly DiceRoller $dice) {}

    /** @return list<RollTable> */
    public function integrate(Campaign $campaign, CatalogEntry $entry): array
    {
        abort_unless($entry->active, 422, 'Este conteúdo não está ativo no catálogo.');
        abort_unless(in_array($entry->kind, ['encounters', 'loot'], true), 422, 'Este conteúdo não possui materialização de campanha.');
        abort_unless(data_get($entry->data, 'integration.schema') === 1, 422, 'O verbete precisa ser reimportado com o schema de integração atual.');

        return $entry->kind === 'encounters'
            ? $this->integrateEncounterTables($campaign, $entry)
            : [$this->integrateLootTable($campaign, $entry)];
    }

    /** @return array{encounterDraft?:EncounterBuilderDraft,loot?:LootResult} */
    public function materializeRoll(RollTable $table, RollTableRoll $roll, array $entry): array
    {
        $integration = data_get($table->metadata, 'fiveTools');
        if (! is_array($integration)) {
            return [];
        }

        return match ($integration['type'] ?? null) {
            'encounter' => ['encounterDraft' => $this->materializeEncounter($table, $roll, $entry)],
            'loot' => ['loot' => $this->materializeLoot($table, $roll, $entry)],
            default => [],
        };
    }

    /** @return list<RollTable> */
    private function integrateEncounterTables(Campaign $campaign, CatalogEntry $entry): array
    {
        $tables = data_get($entry->data, 'integration.automation.encounterTables', []);
        abort_unless(is_array($tables) && $tables !== [], 422, 'Este encontro não contém uma tabela executável.');
        $result = [];
        foreach ($tables as $index => $table) {
            if (! is_array($table) || ! is_array($table['entries'] ?? null) || empty($table['formula'])) {
                continue;
            }
            $existing = RollTable::query()->where('campaign_id', $campaign->id)->get()->first(fn (RollTable $candidate) => (int) data_get($candidate->metadata, 'fiveTools.catalogEntryId') === (int) $entry->id
                && (int) data_get($candidate->metadata, 'fiveTools.tableIndex', -1) === (int) $index
            );
            $payload = [
                'campaign_id' => $campaign->id,
                'name' => mb_substr($entry->name.(count($tables) > 1 ? ' · '.($index + 1) : ''), 0, 160),
                'formula' => $table['formula'],
                'enabled' => true,
                'entries' => $table['entries'],
                'metadata' => [
                    'fiveTools' => [
                        'schema' => 1,
                        'type' => 'encounter',
                        'catalogEntryId' => $entry->id,
                        'source' => $entry->source,
                        'edition' => $entry->edition,
                        'tableIndex' => $index,
                        'gmOnly' => true,
                    ],
                ],
            ];
            if ($existing) {
                $existing->update($payload);
                $result[] = $existing->fresh();
            } else {
                $result[] = RollTable::create($payload);
            }
        }
        abort_if($result === [], 422, 'Nenhuma tabela de encontro compatível foi encontrada.');

        return $result;
    }

    private function integrateLootTable(Campaign $campaign, CatalogEntry $entry): RollTable
    {
        $loot = data_get($entry->data, 'integration.automation.loot');
        abort_unless(is_array($loot), 422, 'Este tesouro não contém automação executável.');
        $entries = is_array($loot['entries'] ?? null) ? $loot['entries'] : [];
        if ($entries === []) {
            $entries = [[
                'min' => 1,
                'max' => 100,
                'label' => $entry->name,
                'result' => [],
            ]];
        }
        $formula = is_string($loot['formula'] ?? null) && $loot['formula'] !== '' ? $loot['formula'] : '1d100';

        $existing = RollTable::query()->where('campaign_id', $campaign->id)->get()->first(fn (RollTable $candidate) => data_get($candidate->metadata, 'fiveTools.type') === 'loot'
            && (int) data_get($candidate->metadata, 'fiveTools.catalogEntryId') === (int) $entry->id
        );
        $payload = [
            'campaign_id' => $campaign->id,
            'name' => mb_substr('Tesouro · '.$entry->name, 0, 160),
            'formula' => $formula,
            'enabled' => true,
            'entries' => $entries,
            'metadata' => [
                'fiveTools' => [
                    'schema' => 1,
                    'type' => 'loot',
                    'catalogEntryId' => $entry->id,
                    'source' => $entry->source,
                    'edition' => $entry->edition,
                    'subtype' => $loot['subtype'] ?? null,
                    'gmOnly' => true,
                ],
            ],
        ];
        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return RollTable::create($payload);
    }

    private function materializeEncounter(RollTable $table, RollTableRoll $roll, array $entry): EncounterBuilderDraft
    {
        $existing = EncounterBuilderDraft::query()
            ->where('campaign_id', $table->campaign_id)
            ->where('metadata->fiveTools->rollTableRollId', $roll->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $entities = data_get($entry, 'result.entities', []);
        $creatures = [];
        $unresolved = [];
        foreach (is_array($entities) ? $entities : [] as $entity) {
            if (! is_array($entity) || ($entity['type'] ?? null) !== 'creature' || empty($entity['name'])) {
                if (is_array($entity)) {
                    $unresolved[] = $entity;
                }

                continue;
            }
            $query = CatalogEntry::query()->where('kind', 'monsters')->where('active', true)->where('name', $entity['name']);
            if (! empty($entity['source'])) {
                $query->where('source', $entity['source']);
            }
            $monster = $query->orderByRaw('edition = ? desc', [$table->campaign->ruleset])->first();
            if (! $monster) {
                $unresolved[] = $entity;

                continue;
            }
            $quantity = $this->quantity($entity['quantity'] ?? 1);
            if ($quantity < 1) {
                continue;
            }
            $snapshot = EncounterBuilderDifficulty::creature($monster, $quantity);
            $key = $monster->id;
            if (isset($creatures[$key])) {
                $creatures[$key]['quantity'] += $quantity;
            } else {
                $creatures[$key] = $snapshot;
            }
        }

        return EncounterBuilderDraft::create([
            'campaign_id' => $table->campaign_id,
            'name' => mb_substr($table->name.' · resultado '.$roll->total, 0, 160),
            'party' => [],
            'creatures' => array_values($creatures),
            'difficulty' => EncounterBuilderDifficulty::summarize(array_values($creatures), [], $table->campaign->ruleset),
            'metadata' => [
                'fiveTools' => [
                    'schema' => 1,
                    'catalogEntryId' => data_get($table->metadata, 'fiveTools.catalogEntryId'),
                    'rollTableId' => $table->id,
                    'rollTableRollId' => $roll->id,
                    'unresolved' => $unresolved,
                    'result' => $entry['result'] ?? [],
                ],
            ],
        ]);
    }

    private function materializeLoot(RollTable $table, RollTableRoll $roll, array $entry): LootResult
    {
        $existing = LootResult::query()->where('roll_table_roll_id', $roll->id)->first();
        if ($existing) {
            return $existing;
        }
        $catalogId = (int) data_get($table->metadata, 'fiveTools.catalogEntryId', 0);
        $source = CatalogEntry::find($catalogId);
        $loot = is_array(data_get($source?->data, 'integration.automation.loot'))
            ? data_get($source->data, 'integration.automation.loot')
            : [];
        $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
        $currency = ['cp' => 0, 'sp' => 0, 'ep' => 0, 'gp' => 0, 'pp' => 0];
        foreach ([$loot['coins'] ?? [], $result['coins'] ?? []] as $coins) {
            foreach (is_array($coins) ? $coins : [] as $coin => $formula) {
                if (array_key_exists($coin, $currency) && is_string($formula)) {
                    $currency[$coin] += $this->rollAmount($formula);
                }
            }
        }
        $items = [];
        $unresolved = [];
        foreach (['gems', 'artObjects'] as $kind) {
            if (is_array($result[$kind] ?? null)) {
                $count = $this->rollAmount((string) ($result[$kind]['amount'] ?? '1'));
                $this->drawSubtable($table->campaign, $source, $kind, $result[$kind]['type'] ?? null, $count, $items, $unresolved);
            }
        }
        foreach (is_array($result['magicItems'] ?? null) ? $result['magicItems'] : [] as $magic) {
            if (! is_array($magic)) {
                continue;
            }
            $count = $this->rollAmount((string) ($magic['amount'] ?? '1'));
            $this->drawSubtable($table->campaign, $source, 'magicItems', $magic['type'] ?? null, $count, $items, $unresolved);
        }
        // Gem/art/magic-item subtables are useful as standalone generators too.
        $subtype = data_get($loot, 'subtype');
        if (in_array($subtype, ['gems', 'artObjects', 'magicItems'], true)) {
            $this->appendItemFromResult($result, $items, $unresolved);
        }

        return LootResult::create([
            'campaign_id' => $table->campaign_id,
            'roll_table_roll_id' => $roll->id,
            'name' => mb_substr($table->name.' · resultado '.$roll->total, 0, 160),
            'items' => $this->mergeItems($items),
            'currency' => $currency,
            'metadata' => [
                'fiveTools' => [
                    'schema' => 1,
                    'catalogEntryId' => $catalogId ?: null,
                    'rollTableId' => $table->id,
                    'rollTableRollId' => $roll->id,
                    'unresolved' => $unresolved,
                    'result' => $result,
                ],
            ],
            'status' => 'draft',
        ]);
    }

    private function drawSubtable(Campaign $campaign, ?CatalogEntry $source, string $subtype, mixed $tableType, int $count, array &$items, array &$unresolved): void
    {
        if ($count <= 0) {
            return;
        }
        $candidates = CatalogEntry::query()->where('kind', 'loot')->where('active', true)
            ->when($source?->edition, fn ($q, $edition) => $q->where('edition', $edition))
            ->get();
        $subtable = $candidates->first(function (CatalogEntry $entry) use ($subtype, $tableType) {
            return data_get($entry->data, 'integration.automation.loot.subtype') === $subtype
                && (string) data_get($entry->data, 'integration.automation.loot.tableType') === (string) $tableType;
        });
        if (! $subtable) {
            $unresolved[] = ['subtable' => $subtype, 'type' => $tableType, 'quantity' => $count];

            return;
        }
        $automation = data_get($subtable->data, 'integration.automation.loot', []);
        $entries = is_array($automation['entries'] ?? null) ? $automation['entries'] : [];
        $formula = (string) ($automation['formula'] ?? '1d100');
        for ($i = 0; $i < min($count, 1000); $i++) {
            $roll = $this->dice->roll($formula)['total'];
            $selected = collect($entries)->first(fn ($candidate) => $roll >= (int) ($candidate['min'] ?? 0) && $roll <= (int) ($candidate['max'] ?? 0));
            if (is_array($selected)) {
                $this->appendItemFromResult(is_array($selected['result'] ?? null) ? $selected['result'] : [], $items, $unresolved);
            }
        }
    }

    private function appendItemFromResult(array $result, array &$items, array &$unresolved): void
    {
        $raw = $result['item'] ?? null;
        if (! is_string($raw) || $raw === '') {
            if ($result !== []) {
                $unresolved[] = $result;
            }

            return;
        }
        if (preg_match('/\\{@item\s+([^}|]+)(?:\\|([^}|]*))?[^}]*\\}/i', $raw, $match)) {
            $name = trim($match[1]);
            $source = trim($match[2] ?? '');
            $catalog = CatalogEntry::query()->where('kind', 'items')->where('active', true)->where('name', $name)
                ->when($source !== '', fn ($q) => $q->where('source', $source))->first();
            $items[] = [
                'name' => $name,
                'slug' => $catalog?->slug,
                'quantity' => 1,
                'description' => $catalog ? (string) data_get($catalog->data, 'description', '') : null,
            ];

            return;
        }
        $plain = trim(preg_replace('/\\{@\\w+\\s+([^}|]+)(?:\\|[^}]*)?\\}/', '$1', $raw) ?? $raw);
        if ($plain !== '') {
            $items[] = ['name' => mb_substr($plain, 0, 160), 'quantity' => 1];
        }
    }

    private function quantity(mixed $quantity): int
    {
        if (is_int($quantity) || is_float($quantity) || (is_string($quantity) && ctype_digit($quantity))) {
            return max(0, (int) $quantity);
        }
        if (is_string($quantity)) {
            return max(0, $this->dice->roll($quantity)['total']);
        }

        return 1;
    }

    private function rollAmount(string $formula): int
    {
        $formula = str_replace(' ', '', $formula);
        if (preg_match('/^(.*)\\*(\\d+)$/', $formula, $match)) {
            return max(0, $this->dice->roll($match[1])['total'] * (int) $match[2]);
        }
        if (ctype_digit($formula)) {
            return max(0, (int) $formula);
        }

        return max(0, $this->dice->roll($formula)['total']);
    }

    /** @return list<array<string,mixed>> */
    private function mergeItems(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['name'])) {
                continue;
            }
            $key = Str::lower(($item['slug'] ?? '').'|'.$item['name']);
            if (! isset($merged[$key])) {
                $merged[$key] = $item;
                $merged[$key]['quantity'] = (int) ($item['quantity'] ?? 1);
            } else {
                $merged[$key]['quantity'] += (int) ($item['quantity'] ?? 1);
            }
        }

        return array_values($merged);
    }
}
