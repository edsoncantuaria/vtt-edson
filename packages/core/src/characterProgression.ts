import { abilityModifier, type Ability, type ActorSystem } from "./dnd";
import type { AdvancementSource } from "./characterBuilder";

const ABILITIES = ["str", "dex", "con", "int", "wis", "cha"] as const;

export type ProgressionResult = {
  system: ActorSystem;
  tasks: string[];
  conflicts: string[];
  choices: string[];
  prerequisites: RequirementCheck[];
  diff: {
    level: [number, number];
    hp: [number, number];
    proficiency: [number, number];
    slots: [ActorSystem["spells"]["slots"], ActorSystem["spells"]["slots"]];
    abilityScores: Partial<Record<Ability, [number, number]>>;
    addedFeatures: string[];
    addedSpells: string[];
  };
};

export type RequirementCheck = {
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
    return [
      {
        ok: score >= minimum,
        text: `${ability.toUpperCase()} ${minimum} (atual ${score})`,
      },
    ];
  });
}

/**
 * 5etools represents ordinary requirements as ANDed ability keys. `or` contains
 * groups whose ability keys are alternatives (for example Fighter STR 13 or DEX 13).
 * Free-form/special requirements stay a manual review item.
 */
export function multiclassRequirements(
  system: ActorSystem,
  cls: AdvancementSource,
): RequirementCheck[] {
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
    checks.push({
      ok: false,
      text: `Requisito especial: ${String(raw.requirementsSpecial)}`,
    });
  return checks;
}

export function canMulticlass(
  system: ActorSystem,
  cls: AdvancementSource,
): boolean {
  const checks = multiclassRequirements(system, cls);
  return checks.length === 0 || checks.every((check) => check.ok);
}

export function subclassStartLevel(subclass: AdvancementSource): number | null {
  const refs: unknown[] = subclass.data.raw?.subclassFeatures ?? [];
  const levels = refs.flatMap((ref) => {
    if (typeof ref !== "string") return [];
    const level = Number(ref.split("|").at(-1));
    return Number.isInteger(level) && level > 0 ? [level] : [];
  });
  return levels.length ? Math.min(...levels) : null;
}

export function subclassMatchesClass(
  subclass: AdvancementSource,
  cls: AdvancementSource,
): boolean {
  const className = subclass.data.raw?.className;
  const classSource = subclass.data.raw?.classSource;
  return className === cls.name && (!classSource || classSource === cls.source);
}

export function proficiencyBonusForLevel(level: number): number {
  return 2 + Math.floor((Math.max(1, level) - 1) / 4);
}

export function hitPointsAtLevel(
  hitDie: number,
  constitutionScore: number,
  firstLevel = false,
): number {
  return Math.max(
    1,
    (firstLevel ? hitDie : Math.floor(hitDie / 2) + 1) +
      abilityModifier(constitutionScore),
  );
}

export function classSpellSlotsAtLevel(
  cls: AdvancementSource,
  level: number,
): Record<string, { max: number; used: number }> | null {
  const group = cls.data.raw?.classTableGroups?.find(
    (candidate: { rowsSpellProgression?: number[][] }) =>
      candidate.rowsSpellProgression,
  );
  const row: number[] | undefined = group?.rowsSpellProgression?.[level - 1];
  if (!Array.isArray(row)) return null;
  return Object.fromEntries(
    row.flatMap((max, index) =>
      max > 0 ? [[String(index + 1), { max, used: 0 }]] : [],
    ),
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
  cls: AdvancementSource,
  options: {
    targetLevel?: number;
    multiclass?: boolean;
    subclass?: AdvancementSource | null;
    subclassCandidates?: AdvancementSource[];
    asi?: Partial<Record<Ability, number>>;
    feat?: AdvancementSource | null;
    learnedSpells?: AdvancementSource[];
    forgetSpellIds?: string[];
    preparedSpellIds?: string[];
    preparedSpellSlugs?: string[];
  } = {},
): ProgressionResult {
  const system = structuredClone(original);
  const totalBefore = system.bio.level;
  system.hitDice ??= {
    die: Number(cls.data.raw?.hd?.faces ?? 8),
    total: Math.max(1, totalBefore),
    used: 0,
  };
  system.proficiencies ??= "";
  system.features ??= [];
  system.spells ??= { slots: {}, known: [] };
  if (Array.isArray(system.spells.slots)) system.spells.slots = {};
  if (totalBefore >= 20) throw new Error("O personagem já está no nível 20.");
  if (
    options.targetLevel !== undefined &&
    options.targetLevel !== totalBefore + 1
  )
    throw new Error("Evolução deve acontecer um nível por vez.");
  if (
    original.preparation?.edition &&
    cls.edition !== original.preparation.edition
  )
    throw new Error("A classe escolhida pertence a outra edição da campanha.");

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
  const subclasses =
    progression.subclasses ??
    (progression.subclass ? [progression.subclass] : []);
  progression.subclasses = subclasses;

  const sameIndex = progression.classes.findIndex(
    (entry) =>
      (entry.classId && entry.classId === cls.id) ||
      (entry.name === cls.name &&
        (!entry.source || entry.source === cls.source)),
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
    progression.classes[sameIndex].hitDie ??= Number(
      cls.data.raw?.hd?.faces ?? system.hitDice.die,
    );
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

  system.bio.class = progression.classes
    .map((entry) => `${entry.name} ${entry.level}`)
    .join(" / ");

  const totalAfter = totalBefore + 1;
  system.bio.level = totalAfter;
  system.proficiencyBonus = proficiencyBonusForLevel(totalAfter);
  system.hitDice.total += 1;

  const hitDie = Number(cls.data.raw?.hd?.faces ?? 8);
  const hpGain = hitPointsAtLevel(hitDie, system.abilities.con.score);
  system.hp.max += hpGain;
  system.hp.value = Math.min(system.hp.max, system.hp.value + hpGain);

  const tasks: string[] = [];
  const conflicts: string[] = [];
  const choices: string[] = [];
  const prerequisites = options.multiclass
    ? multiclassRequirements(original, cls)
    : [];
  if (prerequisites.some((check) => !check.ok))
    conflicts.push("Os pré-requisitos de multiclasse não foram atendidos.");
  if (progression.classes.length === 1) {
    const nextSlots = classSpellSlotsAtLevel(cls, classLevel);
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
  const candidateStartLevels = (options.subclassCandidates ?? [])
    .filter((entry) => subclassMatchesClass(entry, cls))
    .map(subclassStartLevel)
    .filter((level): level is number => level !== null);
  if (
    !subclass &&
    !subclasses.some((entry) => entry.className === cls.name) &&
    candidateStartLevels.some((level) => level <= classLevel)
  )
    choices.push("Escolha uma subclasse antes de confirmar a evolução.");
  if (subclass) {
    if (!subclassMatchesClass(subclass, cls))
      throw new Error(
        "A subclasse selecionada não pertence à classe escolhida.",
      );
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
    if (existingSubclassIndex >= 0)
      subclasses[existingSubclassIndex] = nextSubclass;
    else subclasses.push(nextSubclass);
    progression.subclass = subclasses[0] ?? null;
    if (
      !system.features.some(
        (feature) => feature.name === `${subclass.name} · ${subclass.source}`,
      )
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
    const dice = new Set(
      progression.classes.map((entry) => entry.hitDie).filter(Boolean),
    );
    if (dice.size > 1)
      tasks.push(
        "A ficha resume dados de vida em um único campo; registre a distribuição por classe nas anotações ao gastar dados de vida.",
      );
  }
  const asiAtThisLevel = (cls.data.levelFeatures ?? []).some(
    (feature) =>
      feature.level === classLevel &&
      /ability score improvement|aumento no valor de habilidade/i.test(
        feature.name,
      ),
  );
  if (options.asi && options.feat)
    conflicts.push("Escolha aumento de atributo OU talento, não ambos.");
  if (asiAtThisLevel && !options.asi && !options.feat)
    choices.push(
      "Escolha aumento de atributo (+2 ou +1/+1) ou talento elegível.",
    );
  if (options.asi) {
    const raises = Object.entries(options.asi) as Array<[Ability, number]>;
    if (
      !asiAtThisLevel ||
      !raises.length ||
      raises.some(
        ([, gain]) => !Number.isInteger(gain) || gain < 1 || gain > 2,
      ) ||
      raises.reduce((sum, [, gain]) => sum + gain, 0) !== 2
    )
      conflicts.push("Distribuição de atributo inválida para este nível.");
    else
      for (const [ability, gain] of raises) {
        if (system.abilities[ability].score + gain > 20)
          conflicts.push(
            `${ability.toUpperCase()} não pode ultrapassar 20 pelo aumento comum.`,
          );
        else system.abilities[ability].score += gain;
      }
    const conBefore = abilityModifier(original.abilities.con.score);
    const conAfter = abilityModifier(system.abilities.con.score);
    if (conAfter !== conBefore) {
      const extra = (conAfter - conBefore) * totalAfter;
      system.hp.max += extra;
      system.hp.value = Math.min(system.hp.max, system.hp.value + extra);
    }
  }
  if (options.feat) {
    if (
      !asiAtThisLevel ||
      options.feat.edition !== cls.edition ||
      options.feat.data.raw === undefined
    )
      conflicts.push("O talento não é elegível para esta escolha/edição.");
    else
      system.features.push({
        id: `feat:${options.feat.id}:${totalAfter}`,
        name: options.feat.name,
        source: options.feat.source,
        level: totalAfter,
        description: options.feat.data.description,
      });
  }
  if (options.forgetSpellIds?.length) {
    const knownIds = system.spells.known.map((spell) => spell.id);
    if (options.forgetSpellIds.some((id) => !knownIds.includes(id)))
      conflicts.push("Só é possível substituir uma magia já conhecida.");
    else
      system.spells.known = system.spells.known.filter(
        (spell) => !options.forgetSpellIds?.includes(spell.id),
      );
  }
  const maxSpellLevel = Math.max(
    0,
    ...Object.keys(system.spells.slots).map(Number),
  );
  for (const spell of options.learnedSpells ?? []) {
    if (
      spell.edition !== cls.edition ||
      ((spell.level ?? 0) > maxSpellLevel && (spell.level ?? 0) > 0) ||
      !spell.data.spellLists?.includes(`${cls.source}:${cls.name}`)
    ) {
      conflicts.push(`Magia fora da lista/nível desta classe: ${spell.name}.`);
      continue;
    }
    if (system.spells.known.some((known) => known.slug === spell.slug)) {
      conflicts.push(`Magia já conhecida: ${spell.name}.`);
      continue;
    }
    system.spells.known.push({
      id: crypto.randomUUID(),
      slug: spell.slug ?? null,
      name: spell.name,
      level: spell.level ?? 0,
      description: spell.data.description,
      prepared: false,
    });
  }
  if (options.preparedSpellIds || options.preparedSpellSlugs) {
    const knownIds = system.spells.known.map((spell) => spell.id);
    const knownSlugs = system.spells.known
      .map((spell) => spell.slug)
      .filter(Boolean);
    if (
      options.preparedSpellIds?.some((id) => !knownIds.includes(id)) ||
      options.preparedSpellSlugs?.some((slug) => !knownSlugs.includes(slug))
    )
      conflicts.push(
        "Uma magia preparada não consta no grimório/lista de conhecidas.",
      );
    else
      system.spells.known = system.spells.known.map((spell) => ({
        ...spell,
        prepared:
          !!options.preparedSpellIds?.includes(spell.id) ||
          !!options.preparedSpellSlugs?.includes(spell.slug ?? ""),
      }));
  }
  const expectedCantrips = cls.data.raw.cantripProgression?.[classLevel - 1];
  if (
    expectedCantrips !== undefined &&
    system.spells.known.filter((spell) => spell.level === 0).length !==
      expectedCantrips
  )
    choices.push(
      `Escolha os ${expectedCantrips} truques previstos para ${cls.name} ${classLevel}.`,
    );
  const expectedKnown = cls.data.raw.spellsKnownProgression?.[classLevel - 1];
  if (
    expectedKnown !== undefined &&
    system.spells.known.filter((spell) => spell.level > 0).length !==
      expectedKnown
  )
    choices.push(
      `Revise as ${expectedKnown} magias conhecidas previstas para este nível.`,
    );
  const expectedPrepared =
    cls.data.raw.preparedSpellsProgression?.[classLevel - 1];
  if (
    expectedPrepared !== undefined &&
    system.spells.known.filter((spell) => spell.level > 0 && spell.prepared)
      .length !== expectedPrepared
  )
    choices.push(
      `Prepare ${expectedPrepared} magias conforme o progresso da classe.`,
    );
  tasks.push(
    `PV máximos aumentaram em ${hpGain} usando a média de d${hitDie} + CON.`,
  );
  return {
    system,
    tasks,
    conflicts,
    choices,
    prerequisites,
    diff: {
      level: [totalBefore, totalAfter],
      hp: [original.hp.max, system.hp.max],
      proficiency: [original.proficiencyBonus, system.proficiencyBonus],
      slots: [original.spells.slots, system.spells.slots],
      abilityScores: Object.fromEntries(
        ABILITIES.filter(
          (key) =>
            original.abilities[key].score !== system.abilities[key].score,
        ).map((key) => [
          key,
          [original.abilities[key].score, system.abilities[key].score],
        ]),
      ),
      addedFeatures: system.features
        .filter(
          (feature) =>
            !original.features.some((existing) => existing.id === feature.id),
        )
        .map((feature) => feature.name),
      addedSpells: system.spells.known
        .filter(
          (spell) =>
            !original.spells.known.some((existing) => existing.id === spell.id),
        )
        .map((spell) => spell.name),
    },
  };
}
