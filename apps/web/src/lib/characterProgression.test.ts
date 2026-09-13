import { describe, expect, it } from "vitest";
import { emptyActorSystem } from "@vtt/core";
import type { CatalogEntry } from "./catalog";
import {
  advanceCharacter,
  canMulticlass,
  multiclassRequirements,
  proficiencyBonusForLevel,
  subclassMatchesClass,
  subclassStartLevel,
} from "./characterProgression";

const entry = (
  name: string,
  source: string,
  raw: Record<string, unknown>,
  levelFeatures: { name: string; level: number; description?: string }[] = [],
): CatalogEntry => ({
  id: 10,
  slug: `${name}-${source}`,
  kind: "classes",
  name,
  source,
  edition: source === "XPHB" ? "5e-2024" : "5e-2014",
  data: { raw, levelFeatures },
});

describe("character progression", () => {
  it("checks AND and OR multiclass requirements", () => {
    const system = emptyActorSystem();
    system.abilities.dex.score = 13;
    const fighter = entry("Fighter", "PHB", {
      multiclassing: { requirements: { or: [{ str: 13, dex: 13 }] } },
    });
    expect(canMulticlass(system, fighter)).toBe(true);
    expect(multiclassRequirements(system, fighter)[0].text).toContain("DEX 13");
    const paladin = entry("Paladin", "PHB", {
      multiclassing: { requirements: { str: 13, cha: 13 } },
    });
    expect(canMulticlass(system, paladin)).toBe(false);
  });

  it("advances a class with HP, proficiency, slots and level features", () => {
    const system = emptyActorSystem();
    system.bio.class = "Wizard";
    system.bio.level = 4;
    system.abilities.con.score = 14;
    system.progression = {
      classes: [{ classId: 10, name: "Wizard", source: "PHB", level: 4, hitDie: 6 }],
      subclass: null,
    };
    const wizard = entry(
      "Wizard",
      "PHB",
      {
        hd: { faces: 6 },
        classTableGroups: [{ rowsSpellProgression: [[2], [3], [4, 2], [4, 3], [4, 3, 2]] }],
      },
      [{ name: "Feature 5", level: 5, description: "x" }],
    );
    const result = advanceCharacter(system, wizard).system;
    expect(result.bio.level).toBe(5);
    expect(result.proficiencyBonus).toBe(3);
    expect(result.hp.max).toBe(16);
    expect(result.spells.slots["3"].max).toBe(2);
    expect(result.features.some((feature) => feature.name === "Feature 5")).toBe(true);
  });

  it("reads subclass starting level and proficiency progression", () => {
    const subclass = {
      ...entry("Champion", "PHB", {
        className: "Fighter",
        classSource: "PHB",
        subclassFeatures: ["Champion|Fighter|PHB|Champion|PHB|3"],
      }),
      kind: "subclasses" as const,
    };
    const fighter = entry("Fighter", "PHB", {});
    const wizard = entry("Wizard", "PHB", {});
    expect(subclassStartLevel(subclass)).toBe(3);
    expect(subclassMatchesClass(subclass, fighter)).toBe(true);
    expect(subclassMatchesClass(subclass, wizard)).toBe(false);
    expect(proficiencyBonusForLevel(1)).toBe(2);
    expect(proficiencyBonusForLevel(5)).toBe(3);
    expect(proficiencyBonusForLevel(17)).toBe(6);
  });

  it("keeps the multiclass display in sync when advancing an existing class", () => {
    const system = emptyActorSystem();
    system.bio.level = 2;
    system.bio.class = "Fighter 1 / Wizard 1";
    system.progression = {
      classes: [
        { classId: 10, name: "Fighter", source: "PHB", level: 1, hitDie: 10 },
        { classId: 11, name: "Wizard", source: "PHB", level: 1, hitDie: 6 },
      ],
      subclass: null,
    };
    const fighter = entry("Fighter", "PHB", { hd: { faces: 10 } });
    const result = advanceCharacter(system, fighter).system;
    expect(result.bio.class).toBe("Fighter 2 / Wizard 1");
  });

  it("does not overwrite combined spell slots when advancing an existing multiclass", () => {
    const system = emptyActorSystem();
    system.bio.level = 3;
    system.bio.class = "Fighter 1 / Wizard 2";
    system.spells.slots = { "1": { max: 3, used: 1 }, "2": { max: 2, used: 0 } };
    system.progression = {
      classes: [
        { classId: 12, name: "Fighter", source: "PHB", level: 1, hitDie: 10 },
        { classId: 10, name: "Wizard", source: "PHB", level: 2, hitDie: 6 },
      ],
      subclass: null,
    };
    const wizard = entry("Wizard", "PHB", {
      hd: { faces: 6 },
      classTableGroups: [{ rowsSpellProgression: [[2], [3], [4, 2]] }],
    });
    const result = advanceCharacter(system, wizard);
    expect(result.system.spells.slots).toEqual(system.spells.slots);
    expect(result.tasks.some((task) => task.includes("multiclasse"))).toBe(true);
  });

  it("keeps one subclass per class in multiclass progression", () => {
    const system = emptyActorSystem();
    system.bio.level = 5;
    system.bio.class = "Fighter 3 / Wizard 2";
    system.progression = {
      classes: [
        { classId: 10, name: "Fighter", source: "PHB", level: 3, hitDie: 10 },
        { classId: 11, name: "Wizard", source: "PHB", level: 2, hitDie: 6 },
      ],
      subclasses: [
        {
          subclassId: 20,
          name: "Champion",
          source: "PHB",
          className: "Fighter",
          classSource: "PHB",
        },
      ],
      subclass: {
        subclassId: 20,
        name: "Champion",
        source: "PHB",
        className: "Fighter",
        classSource: "PHB",
      },
    };
    const wizard = { ...entry("Wizard", "PHB", { hd: { faces: 6 } }), id: 11 };
    const evoker = {
      ...entry("Evocation", "PHB", {
        className: "Wizard",
        classSource: "PHB",
        subclassFeatures: ["Evocation|Wizard|PHB|Evocation|PHB|2"],
      }),
      id: 21,
      kind: "subclasses" as const,
    };
    const result = advanceCharacter(system, wizard, { subclass: evoker }).system;
    expect(result.progression?.subclasses?.map((value) => value.name)).toEqual([
      "Champion",
      "Evocation",
    ]);
    expect(result.progression?.subclass?.name).toBe("Champion");
  });
});
