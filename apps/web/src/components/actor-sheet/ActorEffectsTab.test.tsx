import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, emptyActorSystem } from "@vtt/core";
import { ActorEffectsTab } from "./ActorEffectsTab";

describe("gerenciador de efeitos na ficha", () => {
  it("mostra condição, origem, duração por fase e ações auditáveis ao mestre", () => {
    const actor = ActorSchema.parse({
      id: 1,
      campaignId: 1,
      ownerUserId: 1,
      name: "Alvo",
      type: "character",
      system: emptyActorSystem(),
      documents: [],
      activeEffects: [
        {
          id: 11,
          actor_id: 1,
          name: "Teia",
          duration: { unit: "rounds", remaining: 2, phase: "end" },
          conditions: ["restrained"],
          modifiers: [],
          source_label: "Magia · Web",
          visibility: "public",
          metadata: {},
          active: true,
        },
      ],
    });
    const html = renderToStaticMarkup(<ActorEffectsTab actor={actor} canEdit manager />);
    expect(html).toContain("Contido");
    expect(html).toContain("fim do turno");
    expect(html).toContain("Magia · Web");
    expect(html).toContain("Revisar efeito");
    expect(html).toContain("Inspecionar histórico dos efeitos");
    expect(html).toContain("Até descanso curto");
    expect(html).toContain("Somente mestre");
    const player = renderToStaticMarkup(<ActorEffectsTab actor={actor} canEdit />);
    expect(player).not.toContain("Inspecionar histórico dos efeitos");
    expect(player).not.toContain("Revisar efeito");
  });
});
