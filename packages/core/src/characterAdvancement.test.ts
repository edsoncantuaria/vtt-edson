import { describe, expect, it } from "vitest";
import { buildCharacter, type AdvancementSource } from "./characterBuilder";
import { advanceCharacter, hitPointsAtLevel } from "./characterProgression";

const entry = (
  id: number,
  name: string,
  raw: AdvancementSource["data"]["raw"],
  levelFeatures: AdvancementSource["data"]["levelFeatures"] = [],
): AdvancementSource => ({
  id,
  name,
  source: "PHB",
  edition: "5e-2014",
  data: { raw, levelFeatures },
});

const fighter = entry(
  1,
  "Fighter",
  { hd: { faces: 10 }, proficiency: ["str", "con"] },
  [
    { name: "Second Wind", level: 1 },
    { name: "Ability Score Improvement", level: 4 },
  ],
);
const race = entry(2, "Human", { speed: 30 });
const background = entry(3, "Sage", {});
const baseScores = { str: 15, dex: 14, con: 13, int: 12, wis: 10, cha: 8 };

describe("shared character advancement engine", () => {
  it("uses the same hit point rules for creation and sequential level progression", () => {
    const created = buildCharacter(
      "5e-2014",
      fighter,
      race,
      background,
      baseScores,
      {},
      [],
    );
    expect(created.hp.max).toBe(hitPointsAtLevel(10, 13, true));
    const advanced = advanceCharacter(created, fighter, { targetLevel: 2 });
    expect(advanced.system.hp.max).toBe(
      created.hp.max + hitPointsAtLevel(10, 13),
    );
    expect(advanced.diff.level).toEqual([1, 2]);
    expect(advanced.diff.hp).toEqual([11, 18]);
    expect(advanced.system.progression?.classes[0].level).toBe(2);
    expect(() =>
      advanceCharacter(created, fighter, { targetLevel: 4 }),
    ).toThrow("um nível por vez");
  });

  it("previews required ASI/feat and applies retroactive CON HP without changing the input", () => {
    const initial = buildCharacter(
      "5e-2014",
      fighter,
      race,
      background,
      baseScores,
      {},
      [],
    );
    const two = advanceCharacter(initial, fighter).system;
    const three = advanceCharacter(two, fighter).system;
    const awaiting = advanceCharacter(three, fighter);
    expect(awaiting.choices.some((text) => text.includes("atributo"))).toBe(
      true,
    );
    expect(three.bio.level).toBe(3);
    const withAsi = advanceCharacter(three, fighter, { asi: { con: 2 } });
    expect(withAsi.choices).toEqual([]);
    expect(withAsi.system.abilities.con.score).toBe(15);
    expect(withAsi.diff.abilityScores.con).toEqual([13, 15]);
    expect(withAsi.system.hp.max).toBe(awaiting.system.hp.max + 4);
    const invalid = advanceCharacter(three, fighter, { asi: { con: 3 } });
    expect(invalid.conflicts).not.toEqual([]);
  });

  it("requires subclasses at their configured level and rejects cross-edition spells", () => {
    const champion = {
      ...entry(4, "Champion", {
        className: "Fighter",
        classSource: "PHB",
        subclassFeatures: ["Champion|Fighter|PHB|Champion|PHB|3"],
      }),
      edition: "5e-2014",
    };
    const created = buildCharacter(
      "5e-2014",
      fighter,
      race,
      background,
      baseScores,
      {},
      [],
    );
    const two = advanceCharacter(created, fighter).system;
    const missing = advanceCharacter(two, fighter, {
      subclassCandidates: [champion],
    });
    expect(missing.choices.some((text) => text.includes("subclasse"))).toBe(
      true,
    );
    const selected = advanceCharacter(two, fighter, {
      subclass: champion,
      subclassCandidates: [champion],
    });
    expect(selected.choices).toEqual([]);
    const otherEditionSpell: AdvancementSource = {
      ...entry(5, "Bolt", {}),
      edition: "5e-2024",
      level: 0,
      slug: "bolt",
    };
    const wrong = advanceCharacter(two, fighter, {
      learnedSpells: [otherEditionSpell],
    });
    expect(wrong.conflicts.some((text) => text.includes("Bolt"))).toBe(true);
  });

  it("normalizes old sheets without hit dice instead of discarding their character state", () => {
    const created = buildCharacter(
      "5e-2014",
      fighter,
      race,
      background,
      baseScores,
      {},
      [],
    );
    const legacy = structuredClone(created);
    delete (legacy as unknown as Record<string, unknown>).hitDice;
    const advanced = advanceCharacter(legacy, fighter);
    expect(advanced.system.hitDice.total).toBe(2);
    expect(advanced.system.hp.max).toBe(created.hp.max + 7);
    expect(advanced.system.bio.background).toBe("Sage");
  });
});
