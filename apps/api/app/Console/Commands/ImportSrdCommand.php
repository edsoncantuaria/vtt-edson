<?php

namespace App\Console\Commands;

use App\Models\CompendiumItem;
use App\Models\CompendiumMonster;
use App\Models\CompendiumSpell;
use App\Support\Dnd\SrdImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Importa documentos abertos do Open5e v2 para o compêndio local. Complementa o recorte
 * curado do CompendiumSeeder — pode rodar quantas vezes quiser, é upsert por slug.
 */
class ImportSrdCommand extends Command
{
    protected $signature = 'srd:import {--base=https://api.open5e.com/v2} {--document=srd-2014}';

    protected $description = 'Importa spells/itens/monstros do SRD 5.1 via Open5e (api.open5e.com)';

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base'), '/');
        $document = (string) $this->option('document');
        $filter = "?document__key={$document}";

        $this->importPaged("{$base}/spells/{$filter}", function (array $row) use ($document) {
            if (($row['document']['key'] ?? null) !== $document) {
                return;
            }
            $mapped = SrdImporter::mapSpell($row);
            CompendiumSpell::query()->updateOrCreate(['slug' => $mapped['slug']], [
                ...$mapped, 'source' => 'open5e-srd',
            ]);
        }, 'magias');

        foreach (['weapons' => 'weapon', 'armor' => 'armor', 'magicitems' => 'magic-item'] as $endpoint => $type) {
            $this->importPaged("{$base}/{$endpoint}/{$filter}", function (array $row) use ($type, $document) {
                if (($row['document']['key'] ?? null) !== $document) {
                    return;
                }
                $mapped = SrdImporter::mapItem($row, $type);
                CompendiumItem::query()->updateOrCreate(['slug' => $mapped['slug']], [
                    ...$mapped, 'source' => 'open5e-srd',
                ]);
            }, "itens ({$endpoint})");
        }

        $this->importPaged("{$base}/creatures/{$filter}", function (array $row) use ($document) {
            if (($row['document']['key'] ?? null) !== $document) {
                return;
            }
            $mapped = SrdImporter::mapMonster($row);
            CompendiumMonster::query()->updateOrCreate(['slug' => $mapped['slug']], [
                ...$mapped, 'source' => 'open5e-srd',
            ]);
        }, 'monstros');

        $this->info('Import do SRD concluído.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(array<string, mixed>): void  $onRow
     */
    private function importPaged(string $url, callable $onRow, string $label): void
    {
        $this->line("Importando {$label}...");
        $count = 0;
        $next = $url;

        while ($next !== null) {
            $response = Http::timeout(30)->get($next);
            if (! $response->successful()) {
                $this->warn("Falha ao buscar {$next}: HTTP {$response->status()}");

                return;
            }

            $body = $response->json();
            foreach (($body['results'] ?? []) as $row) {
                $onRow($row);
                $count++;
            }
            $next = $body['next'] ?? null;
        }

        $this->line("  -> {$count} registros ({$label}).");
    }
}
