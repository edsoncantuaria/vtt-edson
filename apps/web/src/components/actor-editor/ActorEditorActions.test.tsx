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
    const player = renderToStaticMarkup(
      <ActionsSection system={system} documents={[]} mutate={() => {}} canManage={false} />,
    );
    expect(player).not.toContain("Somente mestre");
  });
});
