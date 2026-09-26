<?php

namespace App\Support\Dnd;

use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CatalogEntry;
use Illuminate\Support\Str;

/** Immutable, catalog-grounded casting options; the client never supplies formulas or spell identity. */
final class Spellcasting
{
    public function profile(Actor $actor, ActorDocument $document): array
    {
        abort_unless($document->actor_id === $actor->id && $document->kind === 'spell', 404, 'Magia não pertence à ficha.');
        $entry = $document->catalogEntry;
        if (! $entry) {
            $origin = data_get($document->data, 'origin.catalogEntryId');
            $entry = $origin ? CatalogEntry::whereKey($origin)->first() : null;
        }
        if (! $entry && $document->slug) {
            $matches = CatalogEntry::query()->where('kind', 'spells')->where('edition', $actor->campaign->ruleset)
                ->where('slug', $document->slug)->where('source', $document->source)->limit(2)->get();
            $entry = $matches->count() === 1 ? $matches->first() : null;
        }
        abort_unless($entry && $entry->kind === 'spells' && $entry->edition === $actor->campaign->ruleset
            && $entry->level !== null && ($actor->campaign->catalog_sources === null
                || in_array($entry->source, $actor->campaign->catalog_sources, true)), 422,
            'Conjuração guiada requer magia do catálogo na edição e fonte da campanha.');
        $known = collect($actor->system['spells']['known'] ?? [])->first(fn ($spell) => (int) ($spell['documentId'] ?? 0) === $document->id
            || ! isset($spell['documentId']) && ($spell['slug'] ?? null) === $entry->slug && ($spell['name'] ?? null) === $entry->name);
        abort_unless($known, 422, 'A magia precisa estar na lista da ficha.');
        $raw = $entry->data['raw'] ?? [];
        $slug = Str::slug($entry->name);
        $kind = match (true) {
            in_array($slug, ['magic-missile', 'misseis-magicos'], true) => 'missiles',
            in_array($slug, ['fireball', 'bola-de-fogo'], true) => 'area-save',
            in_array($slug, ['cure-wounds', 'curar-ferimentos'], true) => 'heal',
            default => 'catalog-action',
        };
        $template = data_get($entry->data, 'integration.automation.actions.0');
        abort_unless($kind !== 'catalog-action' || is_array($template) && ! empty($template['name']), 422,
            'O catálogo ainda não possui automação segura para esta magia; configure uma ação manual.');
        $classes = collect(data_get($actor->system, 'progression.classes', []));
        $spellLists = $entry->data['spellLists'] ?? [];
        if ($classes->isNotEmpty() && $spellLists) {
            abort_unless($classes->contains(fn ($class) => in_array(($class['source'] ?? '').':'.($class['name'] ?? ''), $spellLists, true)),
                422, 'A magia não pertence à lista das classes desta ficha.');
        }
        $matchingClasses = $spellLists ? $classes->filter(fn ($class) => in_array(($class['source'] ?? '').':'.($class['name'] ?? ''), $spellLists, true)) : $classes;
        $isKnownCaster = $matchingClasses->contains(function ($class) use ($actor) {
            $entry = isset($class['classId']) ? CatalogEntry::query()->where('kind', 'classes')
                ->where('edition', $actor->campaign->ruleset)->find($class['classId']) : null;
            if ($entry && data_get($entry->data, 'raw.preparedSpellsProgression') !== null) {
                return false;
            }
            if ($entry && data_get($entry->data, 'raw.spellsKnownProgression') !== null) {
                return true;
            }
            $name = Str::lower($class['name'] ?? '');

            return $actor->campaign->ruleset === '5e-2014' && in_array($name,
                ['bard', 'sorcerer', 'warlock', 'ranger', 'bardo', 'feiticeiro', 'bruxo', 'patrulheiro'], true);
        });
        $prepared = (bool) ($document->prepared || ($known['prepared'] ?? false));
        $ritual = (bool) data_get($raw, 'meta.ritual', false);
        $wizard = $matchingClasses->contains(fn ($class) => in_array(Str::lower($class['name'] ?? ''), ['wizard', 'mago'], true));
        $ritualClass = $actor->campaign->ruleset === '5e-2024' || $matchingClasses->contains(fn ($class) => in_array(Str::lower($class['name'] ?? ''), ['wizard', 'mago', 'cleric', 'clerigo', 'druid', 'druida',
            'bard', 'bardo', 'artificer', 'artifice'], true));
        $components = $raw['components'] ?? [];
        $range = (int) data_get($raw, 'range.distance.amount', match ($kind) {
            'missiles' => 120, 'area-save' => 150, 'heal' => 5, default => 0,
        });
        $concentration = collect($raw['duration'] ?? [])->contains(fn ($duration) => (bool) ($duration['concentration'] ?? false));

        return ['actionId' => 'spell:'.$document->id, 'documentId' => $document->id,
            'name' => $entry->name, 'slug' => $entry->slug, 'source' => $entry->source,
            'edition' => $entry->edition, 'level' => (int) $entry->level, 'kind' => $kind,
            'prepared' => $prepared, 'requiresPreparation' => ! $isKnownCaster && (int) $entry->level > 0,
            'ritual' => $ritual, 'ritualWithoutPreparation' => $wizard && $ritual,
            'components' => ['v' => (bool) ($components['v'] ?? false), 's' => (bool) ($components['s'] ?? false),
                'm' => $components['m'] ?? null], 'rangeFeet' => $range, 'concentration' => $concentration,
            'areaFeet' => $kind === 'area-save' ? 20 : null,
            'target' => $kind === 'catalog-action' ? ($template['target'] ?? 'self') : match ($kind) {
                'missiles' => 'multiple', 'area-save' => 'area', 'heal' => 'single',
            },
            'maxTargets' => $kind === 'catalog-action' ? ($template['maxTargets'] ?? 50) : null,
            'canCast' => $prepared || $isKnownCaster || (int) $entry->level === 0,
            'canRitual' => $ritual && ($wizard || (($prepared || $isKnownCaster) && $ritualClass)),
            'automation' => $kind === 'catalog-action' ? $template : null];
    }

    /** @return array{action:array,profile:array,slotLevel:int|null} */
    public function build(Actor $actor, ActorDocument $document, array $choice): array
    {
        $profile = $this->profile($actor, $document);
        abort_unless(! $profile['components']['m'] || ($choice['componentsConfirmed'] ?? false), 422,
            'Confirme os componentes materiais da magia antes de conjurar.');
        $ritual = (bool) ($choice['ritual'] ?? false);
        abort_unless($ritual ? $profile['canRitual'] : $profile['canCast'], 422,
            'A magia não está preparada ou não pode ser conjurada como ritual nesta classe.');
        $level = $profile['level'];
        $slotLevel = $choice['slotLevel'] ?? null;
        if ($ritual || $level === 0) {
            abort_unless($slotLevel === null, 422, 'Ritual e truque não gastam espaço de magia.');
        } else {
            abort_unless(is_int($slotLevel) && $slotLevel >= $level && $slotLevel <= 9, 422,
                'Escolha espaço de nível igual ou superior ao da magia.');
        }
        $upcast = max(0, ($slotLevel ?? $level) - $level);
        $action = ['id' => $profile['actionId'], 'name' => $profile['name'], 'kind' => 'spell',
            'origin' => 'Catálogo · '.$profile['source'].' · '.$profile['edition'],
            'target' => match ($profile['kind']) {
                'missiles', 'area-save' => 'multiple', 'heal' => 'single', default => 'self'
            },
            'rangeFeet' => $profile['rangeFeet'], 'economy' => 'action',
            'concentration' => $profile['concentration'], 'damageType' => match ($profile['kind']) {
                'missiles' => 'force', 'area-save' => 'fire', default => null,
            }];
        if ($slotLevel !== null) {
            $action['spellSlotLevel'] = $slotLevel;
        }
        if ($profile['kind'] === 'area-save') {
            $action += ['damageFormula' => (8 + $upcast).'d6', 'saveAbility' => 'dex', 'saveEffect' => 'half'];
        } elseif ($profile['kind'] === 'heal') {
            $dice = ($profile['edition'] === '5e-2024' ? 2 : 1) * (1 + $upcast);
            $modifier = (int) floor(((int) data_get($actor->system, 'abilities.'.data_get($actor->system, 'spellcastingAbility', 'int').'.score', 10) - 10) / 2);
            $action['healingFormula'] = $dice.'d8'.($modifier >= 0 ? '+' : '').$modifier;
        } elseif ($profile['kind'] === 'missiles') {
            $action['maxTargets'] = 3 + $upcast;
        } else {
            $action = array_replace($action, $profile['automation']);
            $action['id'] = $profile['actionId'];
            $action['kind'] = 'spell';
            $action['name'] = $profile['name'];
            $action['origin'] = 'Catálogo · '.$profile['source'].' · '.$profile['edition'];
            $action['rangeFeet'] = $profile['rangeFeet'];
            $action['concentration'] = $profile['concentration'];
            unset($action['spellSlotLevel'], $action['documentId'], $action['chargeCost'], $action['resourceId'], $action['resourceCost']);
            if ($slotLevel !== null) {
                $action['spellSlotLevel'] = $slotLevel;
            }
        }

        return ['action' => array_filter($action, fn ($value) => $value !== null), 'profile' => $profile,
            'slotLevel' => $slotLevel, 'upcast' => $upcast, 'ritual' => $ritual];
    }
}
