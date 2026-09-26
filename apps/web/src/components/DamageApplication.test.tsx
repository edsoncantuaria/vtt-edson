import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ActorSchema, ChatMessageSchema, emptyActorSystem } from "@vtt/core";
import { useSession } from "../store/session";
import { DamageApplication } from "./DamageApplication";
import { resolutionTargets } from "../lib/resolutionTargets";

describe("resolução de ataque no chat", () => {
  it("usa apenas os alvos registrados na ação, não a seleção atual de outra ação", () => {
    const actor = ActorSchema.parse({
      id: 12,
      campaignId: 1,
      ownerUserId: null,
      name: "Alvo confirmado",
      type: "monster",
      system: emptyActorSystem(),
    });
    const unrelated = ActorSchema.parse({
      id: 13,
      campaignId: 1,
      ownerUserId: null,
      name: "Token marcado posteriormente",
      type: "monster",
      system: emptyActorSystem(),
    });
    useSession.setState({
      actors: [actor, unrelated],
      role: "gm",
      sceneId: 9,
      targetActorIds: [13],
      user: { id: 1, name: "GM", email: "gm@example.com" },
    });
    const message = ChatMessageSchema.parse({
      id: "attack",
      type: "action",
      userId: 1,
      userName: "GM",
      label: "Arco",
      createdAt: "2026-09-26T18:00:00Z",
      targetMode: "single",
      targetActorIds: [12],
      rolls: [
        {
          kind: "attack",
          formula: "1d20+4",
          total: 17,
          detail: "13 + 4",
          critical: false,
          fumble: false,
        },
        {
          kind: "damage",
          formula: "1d8+2",
          total: 6,
          detail: "4 + 2",
          critical: false,
          fumble: false,
        },
      ],
    });
    const selection = resolutionTargets([actor, unrelated], "gm", 1, message, [13]);
    expect(selection.fixedTargets).toEqual([12]);
    expect(selection.editable.map((entry) => entry.id)).toEqual([12]);
    expect(selection.mapTargets.map((entry) => entry.id)).toEqual([12]);
    const html = renderToStaticMarkup(<DamageApplication message={message} />);
    expect(html).toContain("Resolver alvos e dano");
  });
});
