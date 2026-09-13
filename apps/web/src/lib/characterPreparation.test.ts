import { describe, expect, it } from "vitest";
import { emptyActorSystem } from "@vtt/core";
import type { CatalogEntry } from "./catalog";
import { preparationTasks, spellLimits } from "./characterPreparation";
import { buildCharacter } from "./characterBuilder";
const entry = (name: string, source: string, raw: Record<string, unknown>): CatalogEntry => ({
  id: 1,
  slug: name,
  kind: "classes",
  name,
  source,
  edition: source === "XPHB" ? "5e-2024" : "5e-2014",
  data: { raw },
});
describe("supported spell preparation", () => {
  it("distinguishes wizard spellbook from preparation in each edition without evaluating arbitrary formulas", () => {
    const system = emptyActorSystem();
    system.abilities.int.score = 18;
    system.spells.slots = { "1": { max: 2, used: 0 } };
    expect(
      spellLimits(
        entry("Wizard", "PHB", {
          cantripProgression: [3],
          preparedSpells: "<$level$> + <$int_mod$>",
        }),
        system,
      ),
    ).toEqual({ cantrips: 3, known: 6, prepared: 5, maxLevel: 1 });
    expect(
      spellLimits(
        entry("Wizard", "XPHB", { cantripProgression: [3], preparedSpellsProgression: [4] }),
        system,
      ).prepared,
    ).toBe(4);
    expect(
      spellLimits(entry("Custom", "HB", { preparedSpells: "arbitrary code" }), system).prepared,
    ).toBeNull();
  });
  it("uses level-one spell slots from the actual class progression", () => {
    const cls = entry("Paladin", "XPHB", {
      casterProgression: "artificer",
      classTableGroups: [
        {
          rowsSpellProgression: [
            [2, 0],
            [2, 0],
          ],
        },
      ],
      hd: { faces: 10 },
    });
    const system = buildCharacter(
      "5e-2024",
      cls,
      entry("Human", "XPHB", {}),
      entry("Soldier", "XPHB", {}),
      { str: 15, dex: 14, con: 13, int: 12, wis: 10, cha: 8 },
      {},
      [],
    );
    expect(system.spells.slots).toEqual({ "1": { max: 2, used: 0 } });
  });
  it("retains explicit pending work when selections do not meet supported counts", () => {
    const cls = entry("Bard", "PHB", { cantripProgression: [2], spellsKnownProgression: [4] });
    const system = emptyActorSystem();
    system.spells.slots = { "1": { max: 2, used: 0 } };
    const tasks = preparationTasks(cls, system);
    expect(tasks).toContain("Magias conhecidas/no grimório: 0 de 4.");
    expect(tasks.some((task) => task.includes("equipamento"))).toBe(true);
  });
});
