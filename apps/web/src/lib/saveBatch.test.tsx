import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ChatMessageSchema } from "@vtt/core";
import { SaveBatchResolution } from "../components/SaveBatchResolution";
import { batchReadyIds, pendingNpcIds, unresolvedSaveNames, type SaveBatchRow } from "./saveBatch";

const row = (
  actorId: number,
  name: string,
  type: SaveBatchRow["type"],
  ownerUserId: number | null,
  success: boolean | null,
  applied = false,
): SaveBatchRow => ({
  actorId,
  name,
  type,
  ownerUserId,
  save:
    success === null
      ? null
      : {
          success,
          userId: ownerUserId ?? 1,
          roll: { total: 16, formula: "d20+4", detail: "12 + 4" },
        },
  preview: { damage: success ? 5 : 10, pendingSave: success === null, steps: [] },
  application: applied ? { undone: false, resolution: { damage: 5, userId: 1 } } : null,
});

describe("salvaguardas agrupadas por alvo persistido", () => {
  it("separa NPCs sem rolagem, personagens pendentes e resultados prontos para confirmar", () => {
    const rows = [
      row(1, "Jogador", "character", 4, null),
      row(2, "Goblin", "monster", null, null),
      row(3, "Orc", "monster", null, true),
      row(4, "Aliado", "character", 5, false),
      row(5, "Resolvido", "monster", null, true, true),
    ];
    expect(pendingNpcIds(rows)).toEqual([2]);
    expect(batchReadyIds(rows)).toEqual([3, 4]);
    expect(unresolvedSaveNames(rows)).toEqual(["Jogador", "Goblin"]);
  });

  it("mostra acesso agrupado por ação, sem pedir ID de ficha ou valores técnicos", () => {
    const message = ChatMessageSchema.parse({
      id: "test-action",
      userId: 1,
      userName: "GM",
      type: "action",
      createdAt: "2026-09-26T19:00:00Z",
      targetMode: "multiple",
      targetActorIds: [2, 3],
      save: { ability: "dex", dc: 15, effect: "half" },
    });
    const html = renderToStaticMarkup(<SaveBatchResolution message={message} />);
    expect(html).toContain("Resolver salvaguardas em lote");
    expect(html).toContain("2 alvos");
    expect(html).not.toContain("actorId");
  });
});
