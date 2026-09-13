import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { emptySceneState } from "@vtt/core";
import { useSession, type RoomResult } from "../store/session";
import { api, apiWriteState, setToken } from "./api";
import { updateScene, sceneRequestVersion } from "./scene";

const user = { id: 1, name: "Mestre", email: "gm@example.test" };
function room(id = 1): RoomResult {
  return {
    campaign: { id, name: "Campanha", ruleset: "5e-2024" },
    room: { code: "ABC123" },
    scene: {
      id,
      name: "Cena",
      role: "gm",
      state: emptySceneState(),
      backgroundUrl: null,
    },
  };
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}
const response = (value: unknown, status = 200) =>
  new Response(JSON.stringify(value), {
    status,
    headers: { "Content-Type": "application/json" },
  });
beforeEach(() => {
  const values = new Map<string, string>();
  vi.stubGlobal("localStorage", {
    getItem: (k: string) => values.get(k) ?? null,
    setItem: (k: string, v: string) => values.set(k, v),
    removeItem: (k: string) => values.delete(k),
  });
  vi.stubGlobal("window", new EventTarget());
  setToken("first-token");
  useSession.getState().clearAuth();
  useSession.getState().setAuth(user, "first-token");
  useSession.getState().enterRoom(room());
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});
describe("scene writes and account isolation", () => {
  it("sends scene edits in order and applies authoritative responses without WebSocket", async () => {
    const first = deferred<Response>();
    const state = emptySceneState();
    state.grid.size = 80;
    const fetch = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce(response({ state }));
    vi.stubGlobal("fetch", fetch);
    const one = updateScene(1, "/grid", { size: 80 }, "PATCH");
    const two = updateScene(1, "/chat", { text: "Olá" });
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    expect(sceneRequestVersion().busy).toBe(true);
    first.resolve(response({ state }));
    await Promise.all([one, two]);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(useSession.getState().state.grid.size).toBe(80);
    expect(sceneRequestVersion().busy).toBe(false);
  });
  it("keeps the previous scene state on failure and allows the next action", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValueOnce(response({ message: "Sem permissão" }, 403))
        .mockResolvedValueOnce(response({ state: emptySceneState() })),
    );
    await expect(updateScene(1, "/tokens", { x: 0, y: 0 })).rejects.toThrow("Sem permissão");
    expect(useSession.getState().state.tokens).toHaveLength(0);
    await expect(updateScene(1, "/chat", { text: "Olá" })).resolves.toBeDefined();
    expect(apiWriteState().busy).toBe(false);
  });
  it("does not send queued edits using a different account token", async () => {
    const first = deferred<Response>();
    const fetch = vi.fn().mockReturnValue(first.promise);
    vi.stubGlobal("fetch", fetch);
    const one = updateScene(1, "/chat", { text: "primeira" });
    const two = updateScene(1, "/chat", { text: "segunda" });
    const rejected = expect(two).rejects.toThrow("A mesa foi fechada");
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    setToken("other-account");
    first.resolve(response({ state: emptySceneState() }));
    await one;
    await rejected;
    expect(fetch).toHaveBeenCalledTimes(1);
  });
  it("ignores an old scene response after changing rooms", async () => {
    const first = deferred<Response>();
    vi.stubGlobal("fetch", vi.fn().mockReturnValue(first.promise));
    const request = updateScene(1, "/grid", { size: 99 }, "PATCH");
    await vi.waitFor(() => expect(apiWriteState().busy).toBe(true));
    useSession.getState().enterRoom(room(2));
    const oldState = emptySceneState();
    oldState.grid.size = 99;
    first.resolve(response({ state: oldState }));
    await request;
    expect(useSession.getState().sceneId).toBe(2);
    expect(useSession.getState().state.grid.size).toBe(70);
  });
  it("ignores an in-flight response after changing accounts in the same room", async () => {
    const first = deferred<Response>();
    vi.stubGlobal("fetch", vi.fn().mockReturnValue(first.promise));
    const request = updateScene(1, "/grid", { size: 99 }, "PATCH");
    await vi.waitFor(() => expect(apiWriteState().busy).toBe(true));
    setToken("new-account");
    useSession.getState().enterRoom(room());
    const oldState = emptySceneState();
    oldState.grid.size = 99;
    first.resolve(response({ state: oldState }));
    await request;
    expect(useSession.getState().state.grid.size).toBe(70);
  });
  it("clears role, panel, selected sheet and errors when leaving a room", () => {
    const session = useSession.getState();
    session.setTool("wall");
    session.setPanel("scene");
    session.setSelectedActorId(8);
    session.setError("Antigo");
    session.leaveScene();
    const next = useSession.getState();
    expect(next.user?.id).toBe(user.id);
    expect(next.sceneId).toBeNull();
    expect(next.selectedActorId).toBeNull();
    expect(next.role).toBeNull();
    expect(next.error).toBeNull();
    expect(next.tool).toBe("select");
    expect(next.panel).toBe("chat");
  });
  it("does not expire a newer login when an old authenticated request returns 401", async () => {
    const result = deferred<Response>();
    vi.stubGlobal("fetch", vi.fn().mockReturnValue(result.promise));
    const onExpired = vi.fn();
    window.addEventListener("vtt:unauthorized", onExpired);
    const request = api("/me");
    const rejected = expect(request).rejects.toThrow();
    setToken("new-token");
    result.resolve(response({ message: "Expired" }, 401));
    await rejected;
    expect(onExpired).not.toHaveBeenCalled();
  });
});
