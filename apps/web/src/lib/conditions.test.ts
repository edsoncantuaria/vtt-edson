import { describe, expect, it } from "vitest";
import { ActorSchema, emptyActorSystem } from "@vtt/core";
import { actorConditions, conditionLabel } from "./conditions";

describe("condições visíveis na ficha e no token", () => {
  it("combina condições da ficha com efeitos autorizados, sem duplicatas e com nomes acessíveis", () => {
    const system = emptyActorSystem();
    system.conditions = ["Envenenado", "prone"];
    const actor = ActorSchema.parse({
      id: 1,
      campaignId: 2,
      ownerUserId: 3,
      name: "Herói",
      type: "character",
      system,
      documents: [],
      activeEffects: [
        {
          id: 7,
          actor_id: 1,
          name: "Névoa",
          duration: { unit: "rounds", remaining: 2 },
          conditions: ["poisoned", "restrained"],
          modifiers: [],
          metadata: {},
          active: true,
        },
      ],
    });
    expect(actorConditions(actor)).toEqual(["poisoned", "prone", "restrained"]);
    expect(actorConditions(actor).map(conditionLabel)).toEqual(["Envenenado", "Caído", "Contido"]);
  });

  it("não mostra o que já expirou e mantém condições homebrew identificáveis", () => {
    const system = emptyActorSystem();
    system.conditions = ["Marca caseira"];
    const actor = ActorSchema.parse({
      id: 2,
      campaignId: 2,
      ownerUserId: 3,
      name: "Alvo",
      type: "character",
      system,
      documents: [],
      activeEffects: [
        {
          id: 8,
          actor_id: 2,
          name: "Expirado",
          duration: { unit: "rounds", remaining: 0 },
          conditions: ["blinded"],
          modifiers: [],
          metadata: {},
          active: false,
        },
      ],
    });
    expect(actorConditions(actor)).toEqual(["marca caseira"]);
    expect(conditionLabel("Marca caseira")).toBe("Marca caseira");
  });
});
