import { abilityModifier, type ActorSystem } from "@vtt/core";
import type { CatalogEntry } from "./catalog";

const ABILITIES = ["str", "dex", "con", "int", "wis", "cha"] as const;

export type ProgressionResult = {
  system: ActorSystem;
  tasks: string[];
};

type RequirementCheck = {
  ok: boolean;
  text: string;
};

function requirementPart(
  system: ActorSystem,
  requirements: Record<string, unknown>,
): RequirementCheck[] {
  return ABILITIES.flatMap((ability) => {
    const minimum = requirements[ability];
    if (typeof minimum !== "number") return [];
    const score = system.abilities[ability].score;
    return [{ ok: score >= minimum, text: `${ability.toUpperCase()} ${minimum} (atual ${score})` }];
  });
}

/**
 * 5etools represents ordinary requirements as ANDed ability keys. `or` contains
 * groups whose ability keys are alternatives (for example Fighter STR 13 or DEX 13).
 * Free-form/special requirements stay a manual review item.
 */
export function multiclassRequirements(system: ActorSystem, cls: CatalogEntry): RequirementCheck[] {
  const raw = cls.data.raw?.multiclassing;
  if (!raw) return [];
  const checks = requirementPart(system, raw.requirements ?? {});
  for (const group of raw.requirements?.or ?? []) {
    const alternatives = requirementPart(system, group);
    if (alternatives.length) {
      checks.push({
        ok: alternatives.some((item) => item.ok),
        text: alternatives.map((item) => item.text).join(" ou "),
      });
    }
  }
  if (raw.requirementsSpecial)
    checks.push({ ok: false, text: `Requisito especial: ${String(raw.requirementsSpecial)}` });
  return checks;
}

export function canMulticlass(system: ActorSystem, cls: CatalogEntry): boolean {
  const checks = multiclassRequirements(system, cls);
  return checks.length === 0 || checks.every((check) => check.ok);
}

export function subclassStartLevel(subclass: CatalogEntry): number | null {
  const refs: unknown[] = subclass.data.raw?.subclassFeatures ?? [];
  const levels = refs.flatMap((ref) => {
    if (typeof ref !== "string") return [];
    const level = Number(ref.split("|").at(-1));
    return Number.isInteger(level) && level > 0 ? [level] : [];
  });
  return levels.length ? Math.min(...levels) : null;
}

export function subclassMatchesClass(subclass: CatalogEntry, cls: CatalogEntry): boolean {
  const className = subclass.data.raw?.className;
  const classSource = subclass.data.raw?.classSource;
  return className === cls.name && (!classSource || classSource === cls.source);
}

export function proficiencyBonusForLevel(level: number): number {
  return 2 + Math.floor((Math.max(1, level) - 1) / 4);
}

function slotsAtLevel(
  cls: CatalogEntry,
  level: number,
): Record<string, { max: number; used: number }> | null {
  const group = cls.data.raw?.classTableGroups?.find(
    (candidate: { rowsSpellProgression?: number[][] }) => candidate.rowsSpellProgression,
  );
  const row: number[] | undefined = group?.rowsSpellProgression?.[level - 1];
  if (!Array.isArray(row)) return null;
  return Object.fromEntries(
    row.flatMap((max, index) => (max > 0 ? [[String(index + 1), { max, used: 0 }]] : [])),
  );
}

function mergeProficiencies(current: string, values: unknown[]): string {
  const entries = new Set(
    current
      .split(",")
      .map((part) => part.trim())
      .filter(Boolean),
  );
  for (const value of values) if (typeof value === "string") entries.add(value);
  return [...entries].join(", ");
}

export function advanceCharacter(
  original: ActorSystem,
  cls: CatalogEntry,
  options: { multiclass?: boolean; subclass?: CatalogEntry | null } = {},
): ProgressionResult {
  const system = structuredClone(original);
  const totalBefore = system.bio.level;
  if (totalBefore >= 20) throw new Error("O personagem já está no nível 20.");

  const progression = system.progression ?? {
    classes: [
      {
        classId: system.preparation?.classId,
        name: system.bio.class || cls.name,
        source: system.preparation?.source,
        level: Math.max(1, totalBefore),
        hitDie: system.hitDice.die,
      },
    ],
    subclasses: [],
    subclass: null,
  };
  system.progression = progression;
  const subclasses = progression.subclasses ?? (progression.subclass ? [progression.subclass] : []);
  progression.subclasses = subclasses;

  const sameIndex = progression.classes.findIndex(
    (entry) =>
      (entry.classId && entry.classId === cls.id) ||
      (entry.name === cls.name && (!entry.source || entry.source === cls.source)),
  );
  if (options.multiclass && sameIndex >= 0)
    throw new Error(
      "Essa classe já faz parte da progressão; aumente o nível dela em vez de adicioná-la novamente.",
    );

  let classLevel: number;
  if (sameIndex >= 0) {
    progression.classes[sameIndex].level += 1;
    progression.classes[sameIndex].classId ??= cls.id;
    progression.classes[sameIndex].source ??= cls.source;
    progression.classes[sameIndex].hitDie ??= Number(cls.data.raw?.hd?.faces ?? system.hitDice.die);
    classLevel = progression.classes[sameIndex].level;
  } else {
    const hitDie = Number(cls.data.raw?.hd?.faces ?? 8);
    progression.classes.push({
      classId: cls.id,
      name: cls.name,
      source: cls.source,
      level: 1,
      hitDie,
    });
    classLevel = 1;
  }

  system.bio.class = progression.classes.map((entry) => `${entry.name} ${entry.level}`).join(" / ");

  const totalAfter = totalBefore + 1;
  system.bio.level = totalAfter;
  system.proficiencyBonus = proficiencyBonusForLevel(totalAfter);
  system.hitDice.total += 1;

  const hitDie = Number(cls.data.raw?.hd?.faces ?? 8);
  const hpGain = Math.max(
    1,
    Math.floor(hitDie / 2) + 1 + abilityModifier(system.abilities.con.score),
  );
  system.hp.max += hpGain;
  system.hp.value = Math.min(system.hp.max, system.hp.value + hpGain);

  const tasks: string[] = [];
  if (progression.classes.length === 1) {
    const nextSlots = slotsAtLevel(cls, classLevel);
    if (nextSlots) {
      for (const [level, slot] of Object.entries(nextSlots)) {
        slot.used = Math.min(slot.max, system.spells.slots[level]?.used ?? 0);
      }
      system.spells.slots = nextSlots;
    }
  } else {
    const gained = cls.data.raw?.multiclassing?.proficienciesGained ?? {};
    if (options.multiclass) {
      system.proficiencies = mergeProficiencies(system.proficiencies, [
        ...(gained.armor ?? []),
        ...(gained.weapons ?? []),
        ...(gained.tools ?? []),
      ]);
    }
    tasks.push(
      "Revise espaços de magia de multiclasse conforme a tabela da edição; combinações de conjuradores não são inferidas automaticamente.",
    );
  }

  for (const feature of cls.data.levelFeatures ?? []) {
    if (feature.level !== classLevel) continue;
    if (
      system.features.some(
        (existing) =>
          existing.name === feature.name &&
          existing.level === classLevel &&
          existing.source === cls.source,
      )
    )
      continue;
    system.features.push({
      id: crypto.randomUUID(),
      name: feature.name,
      source: cls.source,
      level: classLevel,
      description: feature.description,
    });
  }

  const subclass = options.subclass;
  if (subclass) {
    if (!subclassMatchesClass(subclass, cls))
      throw new Error("A subclasse selecionada não pertence à classe escolhida.");
    const start = subclassStartLevel(subclass);
    if (start !== null && classLevel < start)
      throw new Error(`Essa subclasse começa no nível ${start} da classe.`);
    const nextSubclass = {
      subclassId: subclass.id,
      name: subclass.name,
      source: subclass.source,
      className: String(subclass.data.raw?.className ?? cls.name),
      classSource: String(subclass.data.raw?.classSource ?? cls.source),
    };
    const existingSubclassIndex = subclasses.findIndex(
      (entry) =>
        entry.className === nextSubclass.className &&
        (!entry.classSource ||
          !nextSubclass.classSource ||
          entry.classSource === nextSubclass.classSource),
    );
    if (
      existingSubclassIndex >= 0 &&
      subclasses[existingSubclassIndex].subclassId !== nextSubclass.subclassId
    ) {
      throw new Error(`${cls.name} já possui uma subclasse registrada.`);
    }
    if (existingSubclassIndex >= 0) subclasses[existingSubclassIndex] = nextSubclass;
    else subclasses.push(nextSubclass);
    progression.subclass = subclasses[0] ?? null;
    if (
      !system.features.some((feature) => feature.name === `${subclass.name} · ${subclass.source}`)
    ) {
      system.features.push({
        id: crypto.randomUUID(),
        name: `${subclass.name} · ${subclass.source}`,
        source: subclass.source,
        level: classLevel,
        description: subclass.data.description,
      });
    }
    for (const feature of subclass.data.levelFeatures ?? []) {
      if (feature.level !== classLevel) continue;
      if (
        !system.features.some(
          (existing) =>
            existing.name === feature.name &&
            existing.level === classLevel &&
            existing.source === subclass.source,
        )
      ) {
        system.features.push({
          id: crypto.randomUUID(),
          name: feature.name,
          source: subclass.source,
          level: classLevel,
          description: feature.description,
        });
      }
    }
  }

  if (progression.classes.length > 1) {
    const dice = new Set(progression.classes.map((entry) => entry.hitDie).filter(Boolean));
    if (dice.size > 1)
      tasks.push(
        "A ficha resume dados de vida em um único campo; registre a distribuição por classe nas anotações ao gastar dados de vida.",
      );
  }
  tasks.push(`PV máximos aumentaram em ${hpGain} usando a média de d${hitDie} + CON.`);
  return { system, tasks };
}
