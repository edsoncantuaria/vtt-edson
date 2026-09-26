import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, emptyActorSystem, type Actor } from "@vtt/core";
import { ActorQuickSheet } from "./ActorQuickSheet";
import { AttributesTab, SpellsTab } from "./ActorSheetTabs";

const noop = () => {};
const noopAsync = async () => {};
const formula = (modifier: number) => `d20${modifier >= 0 ? "+" : ""}${modifier}`;

function character(): Actor {
  return ActorSchema.parse({
    id: 7,
    campaignId: 2,
    ownerUserId: 3,
    type: "character",
    name: "Lyra",
    system: emptyActorSystem(),
    documents: [],
    activeEffects: [],
  });
}

describe("Ficha em modo de jogo", () => {
  it("permite rolar atributos, salvaguardas, perícias e iniciativa sem abrir o editor", () => {
    const html = renderToStaticMarkup(
      <AttributesTab
        actor={character()}
        canEdit
        busy={false}
        mode="normal"
        setMode={noop}
        formula={formula}
        roll={noopAsync}
      />,
    );
    expect(html).toContain("Rolar iniciativa");
    expect(html).toContain("Rolar teste de Força");
    expect(html).toContain("Salvaguarda");
    expect(html).toContain("Perícias");
  });

  it("mostra salvaguardas contra morte para personagem com zero PV e bloqueia espectadores", () => {
    const actor = character();
    actor.system.hp.value = 0;
    const props = {
      actor,
      ruleset: "5e-2014" as const,
      busy: false,
      amount: 1,
      setAmount: noop,
      tab: "Atributos" as const,
      setTab: noop,
      mode: "normal",
      setMode: noop,
      formula,
      roll: noopAsync,
      rollDeathSave: noopAsync,
      change: noopAsync,
      executeAction: noopAsync,
      onEdit: noop,
      onDelete: noop,
    };
    const player = renderToStaticMarkup(<ActorQuickSheet {...props} canEdit />);
    const spectator = renderToStaticMarkup(<ActorQuickSheet {...props} canEdit={false} />);
    expect(player).toContain("Rolar salvaguarda contra morte");
    expect(player).toContain("Sucessos 0/3 · Falhas 0/3");
    expect(spectator).toMatch(/disabled=""[^>]*>.*?Rolar salvaguarda contra morte/);
    actor.system.deathSaves.success = 3;
    const stabilized = renderToStaticMarkup(<ActorQuickSheet {...props} canEdit />);
    expect(stabilized).toMatch(/disabled=""[^>]*>.*?Rolar salvaguarda contra morte/);
  });

  it("conjura pela ação existente e oferece configuração quando a magia não tem automação", () => {
    const actor = character();
    actor.system.spells.known = [
      { id: "spell-1", name: "Mísseis Mágicos", level: 1, prepared: true },
    ];
    actor.system.spells.slots = { "1": { max: 2, used: 0 } };
    actor.system.actions = [
      { id: "cast-1", name: "Mísseis Mágicos", kind: "spell", spellSlotLevel: 1 },
    ];
    const props = {
      actor,
      canEdit: true,
      busy: false,
      change: noopAsync,
      executeAction: noopAsync,
      onEdit: noop,
    };
    const ready = renderToStaticMarkup(<SpellsTab {...props} />);
    expect(ready).toContain("Conjurar Mísseis Mágicos");
    expect(ready).not.toContain("Configurar ação para conjurar");

    actor.system.spells.slots["1"].used = 2;
    const exhausted = renderToStaticMarkup(<SpellsTab {...props} />);
    expect(exhausted).toMatch(/disabled=""[^>]*>.*?Conjurar Mísseis Mágicos/);
    actor.system.actions = [];
    expect(renderToStaticMarkup(<SpellsTab {...props} />)).toContain(
      "Configurar ação para conjurar",
    );
  });
});
