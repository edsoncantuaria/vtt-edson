<?php

namespace App\Support;

use App\Models\Actor;
use App\Models\LootResult;
use Illuminate\Support\Str;

final class LootApplication
{
    public static function apply(LootResult $loot, Actor $actor): Actor
    {
        abort_unless($loot->status === 'draft', 409, 'Este tesouro já foi aplicado.');
        abort_unless((int) $actor->campaign_id === (int) $loot->campaign_id, 422, 'A ficha precisa pertencer à mesma campanha.');
        $system = $actor->system;
        $inventory = $system['inventory'] ?? [];
        foreach ($loot->items ?? [] as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $slug = $item['slug'] ?? null;
            $name = trim((string) ($item['name'] ?? 'Item'));
            $index = collect($inventory)->search(fn ($existing) => ($slug && ($existing['slug'] ?? null) === $slug) || (! $slug && ($existing['name'] ?? '') === $name));
            if ($index === false) {
                $inventory[] = array_filter([
                    'id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $name, 'quantity' => $quantity,
                    'equipped' => false, 'description' => $item['description'] ?? null,
                ], fn ($value) => $value !== null);
            } else {
                $inventory[$index]['quantity'] = (int) ($inventory[$index]['quantity'] ?? 1) + $quantity;
            }
        }
        $system['inventory'] = array_values($inventory);
        $system['currency'] ??= ['cp' => 0, 'sp' => 0, 'ep' => 0, 'gp' => 0, 'pp' => 0];
        foreach (['cp', 'sp', 'ep', 'gp', 'pp'] as $coin) {
            $system['currency'][$coin] = (int) ($system['currency'][$coin] ?? 0) + (int) (($loot->currency ?? [])[$coin] ?? 0);
        }
        $actor->system = $system;
        $actor->save();
        $loot->update(['status' => 'applied', 'applied_actor_id' => $actor->id, 'applied_at' => now()]);

        return $actor->fresh();
    }
}
