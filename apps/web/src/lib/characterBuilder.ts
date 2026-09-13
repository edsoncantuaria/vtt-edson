import {
  emptyActorSystem,
  abilityModifier,
  type Ability,
  type ActorSystem,
  SKILL_ABILITY,
} from "@vtt/core";
import type { CatalogEntry } from "./catalog";
export const STANDARD_SCORES = [15, 14, 13, 12, 10, 8];
export function buildCharacter(
  edition: string,
  cls: CatalogEntry,
  race: CatalogEntry,
  background: CatalogEntry,
  scores: Record<Ability, number>,
  bonuses: Partial<Record<Ability, number>>,
  skills: string[],
): ActorSystem {
  const s = emptyActorSystem();
  for (const k of Object.keys(s.abilities) as Ability[])
    s.abilities[k].score = scores[k] + (bonuses[k] ?? 0);
  s.bio = { ...s.bio, class: cls.name, race: race.name, background: background.name };
  for (const k of cls.data.raw.proficiency ?? []) if (s.saves[k]) s.saves[k].proficient = true;
  const normalizeSkill = (name: string) =>
    Object.keys(SKILL_ABILITY).find(
      (k) => k.toLowerCase() === name.replaceAll(" ", "").toLowerCase(),
    );
  for (const name of [
    ...skills,
    ...Object.keys(background.data.raw.skillProficiencies?.[0] ?? {}).filter(
      (k) => k !== "choose" && background.data.raw.skillProficiencies[0][k] === true,
    ),
  ]) {
    const key = normalizeSkill(name);
    if (key) s.skills[key].proficient = true;
  }
  const speed = race.data.raw.speed;
  s.speed = typeof speed === "number" ? speed : typeof speed?.walk === "number" ? speed.walk : 30;
  s.senses.darkvision = typeof race.data.raw.darkvision === "number" ? race.data.raw.darkvision : 0;
  s.hp.max = Math.max(
    1,
    Number(cls.data.raw.hd?.faces ?? 8) + abilityModifier(s.abilities.con.score),
  );
  s.hp.value = s.hp.max;
  s.ac = 10 + abilityModifier(s.abilities.dex.score);
  s.spellcastingAbility = cls.data.raw.spellcastingAbility ?? "int";
  s.hitDice = { die: Number(cls.data.raw.hd?.faces ?? 8), total: 1, used: 0 };
  s.progression = {
    classes: [
      {
        classId: cls.id,
        name: cls.name,
        source: cls.source,
        level: 1,
        hitDie: Number(cls.data.raw.hd?.faces ?? 8),
      },
    ],
    subclass: null,
  };
  s.proficiencies = [
    ...(cls.data.raw.startingProficiencies?.armor ?? []),
    ...(cls.data.raw.startingProficiencies?.weapons ?? []),
  ]
    .filter((v: unknown) => typeof v === "string")
    .join(", ");
  const firstLevelSlots = cls.data.raw.classTableGroups?.find(
    (group: { rowsSpellProgression?: number[][] }) => group.rowsSpellProgression,
  )?.rowsSpellProgression?.[0];
  if (Array.isArray(firstLevelSlots))
    s.spells.slots = Object.fromEntries(
      firstLevelSlots.flatMap((max: number, index: number) =>
        max > 0 ? [[String(index + 1), { max, used: 0 }]] : [],
      ),
    );
  else if (
    cls.data.raw.casterProgression === "full" ||
    (edition === "5e-2024" && cls.data.raw.casterProgression === "artificer")
  )
    s.spells.slots = { "1": { max: 2, used: 0 } };
  if (cls.data.raw.casterProgression === "pact") s.spells.slots = { "1": { max: 1, used: 0 } };
  s.features = [race, background].map((e) => ({
    id: crypto.randomUUID(),
    name: e.name + " · " + e.source,
    description: e.data.description,
  }));
  for (const f of cls.data.levelFeatures ?? [])
    if (f.level === 1)
      s.features.push({ id: crypto.randomUUID(), name: f.name, description: f.description });
  s.bio.notes = `Regras: ${edition}. Fontes: ${cls.source}, ${race.source}, ${background.source}.\nMagias e escolhas de talentos/características: confira suas opções de nível 1.`;
  return s;
}
