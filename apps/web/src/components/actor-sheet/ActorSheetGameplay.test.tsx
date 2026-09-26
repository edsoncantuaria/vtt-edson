import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, emptyActorSystem, type Actor } from "@vtt/core";
import { ActorQuickSheet } from "./ActorQuickSheet";
import { AttributesTab, SpellsTab } from "./ActorSheetTabs";
import { ActionsTab } from "./ActorSheetTabs";
import { HealingApplication } from "../HealingApplication";
import { healingTargets } from "../../lib/healingTargets";
import type { ChatMessage } from "@vtt/core";

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
  it("mostra ações de cura e habilidade sem exigir IDs nem edição técnica", () => {
    const actor = character();
    actor.system.actions = [
      {
        id: "heal",
        name: "Cura",
        kind: "spell",
        healingFormula: "1d8+3",
        target: "single",
        rangeFeet: 30,
      },
      {
        id: "ward",
        name: "Proteger",
        kind: "feature",
        effect: {
          name: "Guard",
          target: "self",
          trigger: "on-use",
          duration: { unit: "rounds", remaining: 1 },
          modifiers: [],
          conditions: [],
        },
      },
    ];
    const html = renderToStaticMarkup(
      <ActionsTab
        actor={actor}
        canEdit
        busy={false}
        change={noopAsync}
        executeAction={noopAsync}
      />,
    );
    expect(html).toContain("1d8+3");
    expect(html).toContain("Um alvo obrigatório");
    expect(html).toContain("Habilidade");
    expect(html).toContain("Executar ação");
  });

  it("oferece confirmação de cura na ficha acessível ao dono do alvo ou GM", () => {
    const actor = character();
    expect(healingTargets([actor], [actor.id], "gm", 12)).toEqual([actor]);
    expect(healingTargets([actor], [actor.id], "player", actor.ownerUserId ?? undefined)).toEqual([
      actor,
    ]);
    expect(healingTargets([actor], [actor.id], "player", 999)).toEqual([]);
    expect(healingTargets([actor], [actor.id], "observer", actor.ownerUserId ?? undefined)).toEqual(
      [],
    );
    const message: ChatMessage = {
      id: "m1",
      type: "action",
      userId: 3,
      userName: "Mestre",
      createdAt: "2026-09-26T13:00:00Z",
      sourceActorId: actor.id,
      targetActorIds: [actor.id],
      rolls: [
        {
          id: "43fa9151-0e32-43e5-90ef-cbc4c5fe0c10",
          kind: "heal",
          formula: "1d8+3",
          total: 7,
          detail: "4+3",
          critical: false,
          fumble: false,
        },
      ],
    };
    const html = renderToStaticMarkup(<HealingApplication message={message} />);
    expect(html).toContain("Resolver cura");
    expect(html).not.toContain("Aplicar cura"); // preview is obtained from the server first
  });
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
