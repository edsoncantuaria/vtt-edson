import { describe, expect, it } from "vitest";
import { ActorActionSchema } from "@vtt/core";
import { createCustomAction, duplicateActorAction } from "./customActions";

describe("configuração de ações personalizadas", () => {
  it("fornece modelos de tocha, manobra, poção e habilidade sem scripts executáveis", () => {
    const ids = ["a", "b", "c", "d", "e"];
    const presets = (["attack", "torch", "maneuver", "potion", "feature"] as const).map(
      (preset, index) => createCustomAction(preset, ids[index]),
    );
    expect(presets.every((action) => ActorActionSchema.safeParse(action).success)).toBe(true);
    expect(presets[1]).toMatchObject({ name: "Ataque com tocha", damageType: "fire" });
    expect(presets[3]).toMatchObject({ kind: "item", healingFormula: "2d4+2" });
    expect(presets[4]).toMatchObject({ target: "self", kind: "feature" });
  });

  it("duplica também ações de documentos com identificador independente e origem registrada", () => {
    const canonical = ActorActionSchema.parse({
      id: "document:123:0",
      name: "Ação de documento",
      kind: "item",
      visibility: "gm",
      origin: "Catálogo · PHB",
      damageFormula: "1d6",
      documentId: 123,
      chargeCost: 1,
      effect: {
        name: "Marca",
        target: "self",
        trigger: "on-use",
        duration: { unit: "rounds", remaining: 1 },
        modifiers: [],
        conditions: [],
      },
    });
    const copy = duplicateActorAction(canonical, "a-copy");
    expect(copy.id).toBe("a-copy");
    expect(copy.name).toContain("(cópia)");
    expect(copy.origin).toContain("Catálogo · PHB");
    expect(copy.visibility).toBe("public");
    expect(copy.documentId).toBeUndefined();
    expect(copy.chargeCost).toBeUndefined();
    copy.effect!.conditions.push("other");
    expect(canonical.effect?.conditions).toEqual([]);
  });
});
