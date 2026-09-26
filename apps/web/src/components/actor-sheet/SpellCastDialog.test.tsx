import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, emptyActorSystem, emptySceneState } from "@vtt/core";
import type { SpellOption } from "../../lib/spellcasting";
import { SpellCastDialog } from "./SpellCastDialog";

const base: SpellOption = {
  actionId: "spell:9",
  documentId: 9,
  name: "Magic Missile",
  source: "PHB",
  edition: "5e-2014",
  level: 1,
  kind: "missiles",
  prepared: true,
  requiresPreparation: true,
  ritual: false,
  ritualWithoutPreparation: false,
  components: { v: true, s: true, m: null },
  rangeFeet: 120,
  concentration: false,
  areaFeet: null,
  canCast: true,
  canRitual: false,
};
const noop = () => {};
const noopCast = async () => {};

function fixture() {
  const system = emptyActorSystem();
  system.spells.slots = {
    "1": { max: 2, used: 0 },
    "2": { max: 1, used: 0 },
    "3": { max: 1, used: 0 },
  };
  const actor = ActorSchema.parse({
    id: 1,
    campaignId: 1,
    ownerUserId: 1,
    name: "Mago",
    type: "character",
    system,
    documents: [],
    activeEffects: [],
  });
  const state = emptySceneState();
  state.tokens = [
    { id: "mage", name: "Mago", actorId: 1, ownerUserId: 1, x: 0, y: 0, size: 1 },
    { id: "orc", name: "Orc", actorId: null, ownerUserId: null, x: 140, y: 0, size: 1 },
  ];
  return { actor, tokens: state.tokens, selectedTokenIds: ["orc"] };
}

describe("formulário de conjuração por magia", () => {
  it("mísseis pedem distribuição entre alvos, slot e têm cancelar sem ação imediata", () => {
    const { actor, tokens, selectedTokenIds } = fixture();
    const html = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        spell={base}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(html).toContain("Distribuir 3 mísseis");
    expect(html).toContain("Orc");
    expect(html).toContain("Espaço de magia");
    expect(html).toContain("Cancelar");
    expect(html).toContain("Confirmar conjuração");
    expect(html).not.toContain("Centro da área");
  });
  it("bola de fogo pede centro e confirmação de material, e mostra risco a aliados", () => {
    const { actor, tokens, selectedTokenIds } = fixture();
    const spell: SpellOption = {
      ...base,
      kind: "area-save",
      name: "Fireball",
      level: 3,
      areaFeet: 20,
      components: { v: true, s: true, m: "guano e enxofre" },
    };
    const html = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        spell={spell}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(html).toContain("Centro da área");
    expect(html).toContain("raio de 20 pés");
    expect(html).toContain("inclusive aliados e conjurador");
    expect(html).toContain("componentes materiais");
    expect(html).toContain("disabled");
    expect(html).not.toContain("Distribuir 3 mísseis");
  });
  it("cura só pede aliado e espaço, ritual só pede escolha própria sem gastar slot", () => {
    const { actor, tokens, selectedTokenIds } = fixture();
    const healing = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        spell={{ ...base, kind: "heal", name: "Cure Wounds" }}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(healing).toContain("Aliado ou criatura a curar");
    expect(healing).not.toContain("Centro da área");
    const ritual = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        spell={{
          ...base,
          kind: "catalog-action",
          name: "Detect Magic",
          prepared: false,
          canCast: false,
          ritual: true,
          canRitual: true,
          ritualWithoutPreparation: true,
        }}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(ritual).toContain("Conjurar como ritual");
    expect(ritual).toContain("checked");
    expect(ritual).not.toContain("Espaço de magia");
  });
  it("automação genérica pede somente os alvos previstos pelo catálogo", () => {
    const { actor, tokens, selectedTokenIds } = fixture();
    const single = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        spell={{ ...base, kind: "catalog-action", target: "single", name: "Bless" }}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(single).toContain("Alvo da magia");
    expect(single).not.toContain("Distribuir 3 mísseis");
    const multiple = renderToStaticMarkup(
      <SpellCastDialog
        actor={actor}
        tokens={tokens}
        selectedTokenIds={selectedTokenIds}
        spell={{
          ...base,
          kind: "catalog-action",
          target: "multiple",
          maxTargets: 3,
          name: "Bless",
        }}
        busy={false}
        onClose={noop}
        onCast={noopCast}
      />,
    );
    expect(multiple).toContain("Escolha até 3 alvos");
    expect(multiple).toContain("Orc");
  });
});
