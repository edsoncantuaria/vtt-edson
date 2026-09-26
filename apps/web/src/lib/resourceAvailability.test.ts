import { describe, expect, it } from "vitest";
import { ActorSchema, emptyActorSystem } from "@vtt/core";
import { unavailableResource } from "./resourceAvailability";

describe("aviso de consumo na ficha", () => {
  it("mostra indisponibilidade antes da ação e respeita custo padrão configurado", () => {
    const system = emptyActorSystem();
    system.spells.slots = { "1": { max: 1, used: 0 } };
    system.resources = [
      { id: "rage", name: "Fúria", max: 2, used: 1, reset: "long", defaultCost: 2 },
    ];
    const actor = ActorSchema.parse({
      id: 1,
      campaignId: 1,
      ownerUserId: 1,
      type: "character",
      name: "Bárbaro",
      system,
      documents: [],
      activeEffects: [],
    });
    const action = {
      id: "rage",
      name: "Golpe",
      kind: "attack" as const,
      spellSlotLevel: 1,
      resourceId: "rage",
    };
    expect(unavailableResource(actor, action)).toContain("requer 2, disponíveis 1");
    actor.system.resources[0].used = 0;
    expect(unavailableResource(actor, action)).toBeNull();
    actor.system.spells.slots["1"].used = 1;
    expect(unavailableResource(actor, action)).toContain("nível 1 esgotado");
  });

  it("valida inspiração e cargas de item da instância vinculada", () => {
    const system = emptyActorSystem();
    const actor = ActorSchema.parse({
      id: 1,
      campaignId: 1,
      ownerUserId: 1,
      type: "character",
      name: "Herói",
      system,
      documents: [],
      activeEffects: [],
    });
    expect(
      unavailableResource(actor, {
        id: "ins",
        name: "Uso",
        kind: "feature",
        resourceId: "inspiration",
      }),
    ).toContain("Inspiração");
    actor.system.inspiration = true;
    expect(
      unavailableResource(actor, {
        id: "ins",
        name: "Uso",
        kind: "feature",
        resourceId: "inspiration",
      }),
    ).toBeNull();
    expect(
      unavailableResource(actor, { id: "wand", name: "Varinha", kind: "spell", documentId: 55 }),
    ).toContain("Cargas insuficientes");
  });
});
