import { describe, it, expect } from "vitest";
import { buildCharacter } from "./characterBuilder";
import type { CatalogEntry } from "./catalog";
function entry(raw: Record<string, unknown>, name: string): CatalogEntry {
  return {
    id: 1,
    slug: name,
    kind: "classes",
    name,
    source: "XPHB",
    edition: "5e-2024",
    data: {
      raw,
      description: "Description",
      levelFeatures: [
        { name: "Second Wind", level: 1, description: "Heal" },
        { name: "Extra Attack", level: 5, description: "Attack" },
      ],
    },
  };
}
describe("character creation", () => {
  it("uses final constitution for HP and only level-one features", () => {
    const cls = entry({ hd: { faces: 10 }, proficiency: ["str", "con"] }, "Fighter");
    const race = entry({ speed: 35, darkvision: 60 }, "Elf");
    const bg = entry({ skillProficiencies: [{ history: true }] }, "Sage");
    const result = buildCharacter(
      "5e-2024",
      cls,
      race,
      bg,
      { str: 15, dex: 14, con: 13, int: 12, wis: 10, cha: 8 },
      { con: 1, str: 2 },
      ["athletics"],
    );
    expect(result.hp.max).toBe(12);
    expect(result.ac).toBe(12);
    expect(result.saves.con.proficient).toBe(true);
    expect(result.skills.athletics.proficient).toBe(true);
    expect(result.skills.history.proficient).toBe(true);
    expect(result.features.map((f) => f.name)).not.toContain("Extra Attack");
    expect(result.speed).toBe(35);
  });
});
