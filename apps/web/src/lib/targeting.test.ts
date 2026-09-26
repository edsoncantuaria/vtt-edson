import { beforeEach, describe, expect, it } from "vitest";
import { emptySceneState } from "@vtt/core";
import {
  isTargetGesture,
  readableTargetActors,
  reconcileTargetTokens,
  toggleTargetToken,
} from "./targeting";
import { useSession } from "../store/session";

const scene = () => {
  const state = emptySceneState();
  state.tokens = [
    { id: "mine", name: "Hero", x: 0, y: 0, ownerUserId: 3, actorId: 11, size: 1 },
    { id: "enemy", name: "Goblin", x: 70, y: 0, ownerUserId: null, actorId: null, size: 1 },
    { id: "friend", name: "Ally", x: 140, y: 0, ownerUserId: 4, actorId: 12, size: 1 },
  ];
  return state;
};

describe("alvos são handles visíveis de tokens separados da ficha selecionada", () => {
  beforeEach(() => useSession.getState().leaveScene());

  it("marca e desmarca múltiplos tokens visíveis sem depender do actorId privado", () => {
    const state = scene();
    expect(toggleTargetToken(state, [], "enemy")).toEqual(["enemy"]);
    expect(toggleTargetToken(state, ["enemy"], "friend")).toEqual(["enemy", "friend"]);
    expect(toggleTargetToken(state, ["enemy", "friend"], "enemy")).toEqual(["friend"]);
    expect(toggleTargetToken(state, [], "hidden")).toEqual([]);
    expect(reconcileTargetTokens(state, ["enemy", "enemy", "hidden"])).toEqual(["enemy"]);
    expect(readableTargetActors(state, ["mine", "enemy", "friend"])).toEqual([11, 12]);
  });

  it("mantém a seleção e os alvos independentes, remove alvos após refresh e troca de cena", () => {
    const store = useSession.getState();
    store.enterRoom({
      campaign: { id: 1, name: "Campaign", ruleset: "5e-2014" },
      room: { code: "ABC" },
      scene: { id: 9, name: "First", role: "player", state: scene(), backgroundUrl: null },
    });
    useSession.getState().setSelectedActorId(11);
    useSession.getState().setTargetTokenIds(["enemy", "friend"]);
    expect(useSession.getState().targetTokenIds).toEqual(["enemy", "friend"]);
    expect(useSession.getState().targetActorIds).toEqual([12]);
    expect(useSession.getState().selectedActorId).toBe(11);
    const privateUpdate = scene();
    privateUpdate.tokens[2].actorId = null;
    useSession.getState().patchState(privateUpdate);
    expect(useSession.getState().targetTokenIds).toEqual(["enemy", "friend"]);
    expect(useSession.getState().targetActorIds).toEqual([]);
    useSession.getState().toggleTargetToken("friend");
    expect(useSession.getState().targetTokenIds).toEqual(["enemy"]);
    const hidden = scene();
    hidden.tokens.splice(1, 1);
    useSession.getState().patchState(hidden);
    expect(useSession.getState().targetTokenIds).toEqual([]);
    expect(useSession.getState().selectedActorId).toBe(11);
    useSession.getState().setTargetTokenIds(["mine"]);
    useSession
      .getState()
      .enterScene({ id: 10, name: "Second", role: "player", state: scene(), backgroundUrl: null });
    expect(useSession.getState().targetTokenIds).toEqual([]);
    expect(useSession.getState().targetActorIds).toEqual([]);
    expect(useSession.getState().selectedActorId).toBeNull();
  });

  it("Shift/clique e toque no modo de alvo não movem token, enquanto toque normal seleciona o token próprio", () => {
    expect(
      isTargetGesture({ targetingMode: false, modifier: true, canEditScene: true, ownToken: true }),
    ).toBe(true);
    expect(
      isTargetGesture({ targetingMode: true, modifier: false, canEditScene: true, ownToken: true }),
    ).toBe(true);
    expect(
      isTargetGesture({
        targetingMode: false,
        modifier: false,
        canEditScene: false,
        ownToken: false,
      }),
    ).toBe(true);
    expect(
      isTargetGesture({
        targetingMode: false,
        modifier: false,
        canEditScene: false,
        ownToken: true,
      }),
    ).toBe(false);
  });
});
