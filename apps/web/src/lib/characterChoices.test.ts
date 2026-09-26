import { describe, expect, it } from "vitest";
import type { CatalogEntry } from "./catalog";
import {
  fixedOriginFeats,
  originChoiceIssues,
  originChoices,
  requiresOriginFeat,
} from "./characterChoices";

const entry = (name: string, raw: CatalogEntry["data"]["raw"]): CatalogEntry => ({
  id: 1,
  kind: "races",
  name,
  slug: name,
  source: "XPHB",
  edition: "5e-2024",
  data: { raw },
});

describe("conditional character choices", () => {
  it("separates fixed grants and mandatory language/tool choices", () => {
    const rules = originChoices(
      entry("Elf", {
        languageProficiencies: [
          { common: true, choose: { count: 1, from: ["elvish", "dwarvish"] } },
        ],
      }),
      entry("Artisan", {
        toolProficiencies: [{ choose: { count: 1, from: ["smith", "weaver"] } }],
      }),
    );
    expect(rules[0].fixed).toEqual(["common"]);
    expect(originChoiceIssues(rules, {})).toHaveLength(2);
    expect(
      originChoiceIssues(rules, {
        "race-languageProficiencies": ["elvish"],
        "background-toolProficiencies": ["smith"],
      }),
    ).toEqual([]);
    expect(
      originChoiceIssues(rules, {
        "race-languageProficiencies": ["orc"],
        "background-toolProficiencies": ["smith"],
      }),
    ).toHaveLength(1);
  });

  it("marks unsupported open selections and detects fixed versus selectable origin feats", () => {
    const background = entry("Sage", { feats: [{ "Magic Initiate|XPHB": true }, { any: 1 }] });
    expect(fixedOriginFeats(background)).toEqual(["Magic Initiate"]);
    expect(requiresOriginFeat(background)).toBe(true);
    expect(
      originChoiceIssues(
        originChoices(entry("Human", { languageProficiencies: [{ anyStandard: 1 }] }), null),
        {},
      ),
    ).toHaveLength(1);
  });
  it("includes sub-race grants and mandatory origin skill choices independently", () => {
    const race = entry("Elf", { languageProficiencies: [{ common: true }] });
    const subrace = entry("High Elf", {
      languageProficiencies: [{ choose: { count: 1, from: ["elvish", "gnomish"] } }],
    });
    const bg = entry("Sage", {
      skillProficiencies: [{ history: true, choose: { count: 1, from: ["arcana", "nature"] } }],
    });
    const rules = originChoices(race, bg, subrace);
    expect(rules.map((rule) => rule.id)).toContain("subrace-languageProficiencies");
    expect(
      originChoiceIssues(rules, {
        "subrace-languageProficiencies": ["elvish"],
        "background-skillProficiencies": ["arcana"],
      }),
    ).toEqual([]);
  });
});
