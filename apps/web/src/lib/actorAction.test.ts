import { beforeEach, describe, expect, it, vi } from "vitest";
import { ActorSchema, emptyActorSystem, emptySceneState } from "@vtt/core";
import { executeActorAction } from "./actorAction";
import { updateScene } from "./scene";

vi.mock("./scene", () => ({ updateScene: vi.fn() }));
const send = vi.mocked(updateScene);

function actor() {
  const system = emptyActorSystem();
  system.actions = [
    {
      id: "ward",
      name: "Proteção",
      kind: "feature",
      target: "self",
      effect: {
        name: "Proteção",
        target: "self",
        trigger: "on-use",
        duration: { unit: "rounds", remaining: 1 },
        modifiers: [],
        conditions: [],
      },
    },
    { id: "heal", name: "Cura", kind: "spell", target: "single", healingFormula: "1d8+2" },
  ];
  return ActorSchema.parse({
    id: 7,
    campaignId: 3,
    ownerUserId: 5,
    type: "character",
    name: "Hero",
    system,
  });
}

describe("shared actor action entrypoint", () => {
  beforeEach(() => {
    send.mockReset();
    send.mockResolvedValue({ state: emptySceneState() });
  });

  it("sheet, spell button and hotbar all call the canonical endpoint with a stable request key", async () => {
    const id = "43fa9151-0e32-43e5-90ef-cbc4c5fe0c10";
    await executeActorAction(9, actor(), "heal", id, "normal", [12, 12]);
    expect(send).toHaveBeenCalledWith(9, "/actions", {
      actorId: 7,
      actionId: "heal",
      requestId: id,
      mode: "normal",
      targetActorIds: [12],
    });
    await executeActorAction(9, actor(), "ward", id, "normal", [12]);
    expect(send).toHaveBeenLastCalledWith(9, "/actions", {
      actorId: 7,
      actionId: "ward",
      requestId: id,
      mode: "normal",
      targetActorIds: [7],
    });
  });

  it("does not send an action ID missing from the current sheet", async () => {
    expect(() => executeActorAction(9, actor(), "missing", "id", "normal", [])).toThrow(
      "não existe",
    );
    expect(send).not.toHaveBeenCalled();
  });
});
