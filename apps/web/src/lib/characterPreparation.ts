import { abilityModifier, type Ability, type ActorSystem } from "@vtt/core";
import type { CatalogEntry } from "./catalog";
import { hasStructuredStartingEquipment } from "./startingEquipment";

/** Level-one, single-class guidance. Unknown progressions stay explicitly pending. */
export function spellLimits(cls: CatalogEntry, system: ActorSystem) {
  const raw = cls.data.raw;
  const index = system.bio.level - 1;
  const cantrips = raw.cantripProgression?.[index] ?? 0;
  let prepared: number | null = raw.preparedSpellsProgression?.[index] ?? null;
  const known: number | null =
    cls.name === "Wizard" && ["PHB", "XPHB"].includes(cls.source) && index === 0
      ? 6
      : (raw.spellsKnownProgression?.[index] ?? null);
  if (typeof raw.preparedSpells === "string") {
    const match = raw.preparedSpells.match(/^<\$level\$> \+ <\$(str|dex|con|int|wis|cha)_mod\$>$/);
    if (match)
      prepared = Math.max(
        1,
        system.bio.level + abilityModifier(system.abilities[match[1] as Ability].score),
      );
  }
  const hasSlots = Object.values(system.spells.slots).some((slot) => slot.max > 0);
  return {
    cantrips,
    known: hasSlots ? known : 0,
    prepared: hasSlots ? prepared : 0,
    maxLevel: hasSlots
      ? Math.max(
          ...Object.entries(system.spells.slots)
            .filter(([, value]) => value.max > 0)
            .map(([key]) => Number(key)),
        )
      : 0,
  };
}

export function spellSelectionIssues(cls: CatalogEntry, system: ActorSystem): string[] {
  const limits = spellLimits(cls, system);
  const spells = system.spells.known;
  const tasks: string[] = [];
  const cantrips = spells.filter((spell) => spell.level === 0).length;
  const known = spells.filter((spell) => spell.level > 0).length;
  const prepared = spells.filter((spell) => spell.level > 0 && spell.prepared).length;
  if (cantrips !== limits.cantrips)
    tasks.push(
      `Truques da classe: ${cantrips} de ${limits.cantrips}. Revise magias extras da origem separadamente.`,
    );
  if (limits.known !== null && known !== limits.known)
    tasks.push(`Magias conhecidas/no grimório: ${known} de ${limits.known}.`);
  if (limits.prepared !== null && prepared !== limits.prepared)
    tasks.push(`Magias preparadas: ${prepared} de ${limits.prepared}.`);
  if (spells.some((spell) => spell.level > limits.maxLevel))
    tasks.push(
      "Há magias acima do nível dos espaços disponíveis; confirme a origem ou corrija a seleção.",
    );
  return tasks;
}

export function preparationTasks(
  cls: CatalogEntry,
  system: ActorSystem,
  background?: CatalogEntry | null,
): string[] {
  const tasks = spellSelectionIssues(cls, system);
  const limits = spellLimits(cls, system);
  if (limits.known === null && limits.prepared === null && limits.maxLevel > 0)
    tasks.push("Progressão de conjuração não reconhecida. Mestre deve revisar os limites.");
  if (!hasStructuredStartingEquipment(cls))
    tasks.push(
      "Conferir escolhas de equipamento da classe quando não houver dados estruturados no catálogo.",
    );
  if (background && !hasStructuredStartingEquipment(background))
    tasks.push(
      "Conferir escolhas de equipamento do antecedente quando não houver dados estruturados no catálogo.",
    );
  tasks.push("Conferir talentos, idiomas, ferramentas e escolhas condicionais da origem/classe.");
  if (system.bio.level !== 1 && !system.progression)
    tasks.push(
      "Esta ficha antiga não possui metadados de progressão; vincule a classe antes de usar evolução assistida.",
    );
  return tasks;
}
