<?php

namespace App\Console\Commands;

use App\Models\CatalogEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnrichSpellListsCommand extends Command
{
    protected $signature = 'compendium:spell-lists {file : gendata-spell-source-lookup.json do 5etools}';

    protected $description = 'Associa listas de classe às magias sem substituir arte, descrições ou fichas.';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error('Arquivo não encontrado.');

            return self::FAILURE;
        }
        $lookup = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        DB::transaction(function () use ($lookup, &$count) {
            CatalogEntry::where('kind', 'spells')->chunkById(100, function ($entries) use ($lookup, &$count) {
                foreach ($entries as $entry) {
                    $classes = $lookup[strtolower($entry->source)][strtolower($entry->name)]['class'] ?? [];
                    $data = $entry->data;
                    $data['spellLists'] = [];
                    foreach ($classes as $source => $names) {
                        foreach ($names as $name => $value) {
                            if ($value) {
                                $data['spellLists'][] = $source.':'.$name;
                            }
                        }
                    }
                    $entry->update(['data' => $data]);
                    $count++;
                }
            });
        });
        $this->info($count.' magias atualizadas.');

        return self::SUCCESS;
    }
}
