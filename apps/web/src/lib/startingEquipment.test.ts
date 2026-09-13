import { describe, expect, it } from "vitest";
import type { CatalogEntry } from "./catalog";
import {
  combineStartingEquipmentPlans,
  resolveStartingEquipment,
  startingEquipmentPlan,
} from "./startingEquipment";

const cls = (defaultData: unknown[]): CatalogEntry => ({
  id: 1,
  slug: "fighter",
  kind: "classes",
  name: "Fighter",
  source: "XPHB",
  edition: "5e-2024",
  data: { raw: { startingEquipment: { defaultData } } },
});

describe("starting equipment", () => {
  it("parses concrete A/B choices, quantities and copper values", () => {
    const plan = startingEquipmentPlan(
      cls([
        { a: ["chain mail|XPHB"], b: [{ item: "leather armor|XPHB", quantity: 1 }] },
        { A: [{ item: "arrow|XPHB", quantity: 20 }], B: [{ value: 1000 }] },
      ]),
    );
    const result = resolveStartingEquipment(plan, { "0": "a", "1": "B" }, []);
    expect(result.inventory[0].name).toBe("chain mail");
    expect(result.currencyGp).toBe(10);
    expect(result.currencyCp).toBe(0);
    expect(result.complete).toBe(true);
  });

  it("keeps copper remainder when converting a value option", () => {
    const plan = startingEquipmentPlan(cls([{ a: [{ value: 150 }], b: ["club|XPHB"] }]));
    const result = resolveStartingEquipment(plan, { "0": "a" }, []);
    expect(result.currencyGp).toBe(1);
    expect(result.currencyCp).toBe(50);
  });

  it("supports a direct background array and an explicit C gold option", () => {
    const background = {
      ...cls([]),
      kind: "backgrounds" as const,
      data: {
        raw: {
          startingEquipment: [
            {
              A: [{ item: "book|xphb", displayName: "Book (Prayers)" }, { value: 800 }],
              C: [{ value: 5000 }],
            },
          ],
        },
      },
    };
    const plan = startingEquipmentPlan(background);
    expect(plan.groups[0].options.map((option) => option.key)).toEqual(["A", "C"]);
    expect(resolveStartingEquipment(plan, { "0": "A" }, []).inventory[0].name).toBe(
      "Book (Prayers)",
    );
    expect(resolveStartingEquipment(plan, { "0": "C" }, []).currencyGp).toBe(50);
  });

  it("does not silently discard special or contained-value equipment", () => {
    const background = {
      ...cls([]),
      kind: "backgrounds" as const,
      data: {
        raw: {
          startingEquipment: [
            { _: [{ special: "vestments" }, { item: "pouch|phb", containsValue: 1500 }] },
          ],
        },
      },
    };
    const result = resolveStartingEquipment(startingEquipmentPlan(background), {}, []);
    expect(result.complete).toBe(false);
    expect(result.manualTypes).toContain("vestments");
    expect(result.manualTypes.some((type) => type.includes("1500"))).toBe(true);
  });

  it("requires catalog choices for generic equipment types", () => {
    const plan = startingEquipmentPlan(cls([{ _: [{ equipmentType: "martial weapon" }] }]));
    const result = resolveStartingEquipment(plan, {}, []);
    expect(result.complete).toBe(false);
    expect(result.issues.join(" ")).toContain("martial weapon");
  });

  it("combines structured class and background equipment without id collisions", () => {
    const combined = combineStartingEquipmentPlans(
      startingEquipmentPlan(cls([{ _: ["dagger|XPHB"] }])),
      startingEquipmentPlan(cls([{ _: [{ item: "dagger|XPHB", quantity: 2 }] }])),
    );
    const result = resolveStartingEquipment(combined, {}, []);
    expect(result.inventory).toHaveLength(1);
    expect(result.inventory[0].quantity).toBe(3);
  });
});
