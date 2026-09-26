import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { emptyActorSystem } from "@vtt/core";
import { ActionsSection } from "./ActorEditorActions";

describe("editor configurável de ações", () => {
  it("oferece modelos e duplicação de ações manuais e canônicas", () => {
    const system = emptyActorSystem();
    system.actions = [
      { id: "manual", name: "Golpe", kind: "attack", attackFormula: "1d20+2" },
      { id: "document:21:0", name: "Ação canônica", kind: "item", damageFormula: "1d6" },
    ];
    const html = renderToStaticMarkup(
      <ActionsSection system={system} documents={[]} mutate={() => {}} canManage />,
    );
    expect(html).toContain("Criar ação sem código");
    expect(html).toContain("Ataque com tocha");
    expect(html).toContain("Poção de cura");
    expect(html).toContain("Duplicar Golpe");
    expect(html).toContain("Duplicar como ação independente");
    expect(html).toContain("Origem da ação");
    expect(html).toContain("Somente mestre");
    expect(html).toContain("Componentes de dano por tipo");
    expect(html).toContain("Adicionar componente tipado");
    const player = renderToStaticMarkup(
      <ActionsSection system={system} documents={[]} mutate={() => {}} canManage={false} />,
    );
    expect(player).not.toContain("Somente mestre");
  });
  it("expõe fórmula e tipo por componente e permite configurar redução fixa na ficha", async () => {
    const system = emptyActorSystem();
    system.damageReduction = 3;
    system.actions = [
      {
        id: "mixed",
        name: "Ataque tipado",
        kind: "attack",
        damageParts: [
          { formula: "2d6", damageType: "fire" },
          { formula: "1d8", damageType: "slashing" },
        ],
      },
    ];
    const html = renderToStaticMarkup(
      <ActionsSection system={system} documents={[]} mutate={() => {}} canManage />,
    );
    expect(html).toContain("Componente 1");
    expect(html).toContain("Componente 2");
    expect(html).toContain("2d6");
    expect(html).toContain("1d8");
    expect(html).toContain("Remover componente 2");
    const { StorySection } = await import("./ActorEditorIdentity");
    const defense = renderToStaticMarkup(<StorySection system={system} mutate={() => {}} />);
    expect(defense).toContain("Redução fixa de dano");
    expect(defense).toContain('value="3"');
  });
});
