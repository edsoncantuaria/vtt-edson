<?php

namespace Database\Seeders;

use App\Models\CompendiumItem;
use App\Models\CompendiumMonster;
use App\Models\CompendiumSpell;
use Illuminate\Database\Seeder;

/**
 * Compêndio inicial: recorte curado do SRD 5.1 (D&D 5e, licença OGL), escrito à mão.
 * Cobre o suficiente pra a mesa funcionar sem depender de rede. Pra importar o SRD
 * completo via API pública do Open5e, rode `php artisan srd:import`.
 */
class CompendiumSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/srd-sample.json');
        if (! file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['spells'] ?? [] as $spell) {
            CompendiumSpell::query()->updateOrCreate(
                ['slug' => $spell['slug']],
                ['name' => $spell['name'], 'level' => $spell['level'], 'school' => $spell['school'], 'data' => $spell['data'], 'source' => 'srd-curated']
            );
        }

        foreach ($data['items'] ?? [] as $item) {
            CompendiumItem::query()->updateOrCreate(
                ['slug' => $item['slug']],
                ['name' => $item['name'], 'type' => $item['type'], 'data' => $item['data'], 'source' => 'srd-curated']
            );
        }

        foreach ($data['monsters'] ?? [] as $monster) {
            CompendiumMonster::query()->updateOrCreate(
                ['slug' => $monster['slug']],
                ['name' => $monster['name'], 'challenge_rating' => $monster['challenge_rating'], 'data' => $monster['data'], 'source' => 'srd-curated']
            );
        }
    }
}
