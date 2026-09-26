import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, emptyActorSystem } from "@vtt/core";
import { ResourcePoolStrip } from "./ResourcePoolStrip";

describe("painel canônico de recursos", () => {
  it("mostra origem, edição, custo e recuperação, separando espaços e demais recursos", () => {
    const actor = ActorSchema.parse({
      id: 4,
      campaignId: 3,
      ownerUserId: 4,
      name: "Herói",
      type: "character",
      system: emptyActorSystem(),
      documents: [],
      activeEffects: [],
      resourcePools: [
        {
          id: "slot:1",
          actorId: 4,
          kind: "spell-slot",
          name: "Espaço nível 1",
          current: 1,
          max: 2,
          used: 1,
          source: "spells.slots",
          edition: "5e-2024",
          defaultCost: 1,
          recovery: { short: "none", long: "full" },
        },
        {
          id: "rage",
          actorId: 4,
          kind: "rage",
          name: "Fúria",
          current: 2,
          max: 3,
          used: 1,
          source: "Barbarian · XPHB",
          edition: "5e-2024",
          defaultCost: 2,
          recovery: { short: "one", long: "full" },
        },
      ],
    });
    const others = renderToStaticMarkup(
      <ResourcePoolStrip actor={actor} filter="other" canEdit busy={false} />,
    );
    expect(others).toContain("Fúria");
    expect(others).toContain("Barbarian · XPHB");
    expect(others).toContain("custo padrão 2");
    expect(others).toContain("recupera 1");
    expect(others).toContain("Inspecionar histórico de recursos");
    expect(others).not.toContain("Espaço nível 1");
    const spells = renderToStaticMarkup(
      <ResourcePoolStrip actor={actor} filter="slots" canEdit busy={false} />,
    );
    expect(spells).toContain("Espaço nível 1");
    expect(spells).not.toContain("Barbarian · XPHB");
  });
});
