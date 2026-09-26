import { beforeEach, describe, expect, it, vi } from "vitest";
import { rollHistory, rollHistoryPage, rollToChat } from "./roll";
import { updateScene } from "./scene";
import { api } from "./api";

vi.mock("./scene", () => ({ updateScene: vi.fn() }));
vi.mock("./api", () => ({ api: vi.fn() }));
const update = vi.mocked(updateScene);
const get = vi.mocked(api);

describe("server-authoritative roll requests", () => {
  beforeEach(() => {
    update.mockReset();
    get.mockReset();
  });

  it("sends the same retry key, context and visibility without a client total", async () => {
    update.mockResolvedValue({ state: {} } as Awaited<ReturnType<typeof updateScene>>);
    const requestId = "43fa9151-0e32-43e5-90ef-cbc4c5fe0c10";
    const options = {
      requestId,
      actorId: 9,
      context: "save" as const,
      mode: "advantage" as const,
      visibility: "gm" as const,
      extraDice: "d4",
    };
    await rollToChat(3, "d20+2", "Hero · Destreza", options);
    await rollToChat(3, "d20+2", "Hero · Destreza", options);
    expect(update).toHaveBeenCalledTimes(2);
    expect(update.mock.calls[0]).toEqual([
      3,
      "/rolls",
      {
        requestId,
        actorId: 9,
        context: "save",
        mode: "advantage",
        visibility: "gm",
        formula: "d20+2",
        label: "Hero · Destreza",
        extraDice: "d4",
      },
    ]);
    expect(update.mock.calls[1]).toEqual(update.mock.calls[0]);
    expect(JSON.stringify(update.mock.calls[0])).not.toContain("total");
  });

  it("rejects a history row missing its canonical roll ID", async () => {
    get.mockResolvedValueOnce({ rolls: [{ formula: "d20", total: 20 }] });
    await expect(rollHistory(3)).rejects.toThrow();
    expect(get).toHaveBeenCalledWith("/scenes/3/rolls");
  });

  it("fetches older persisted results by page without mixing their visibility client-side", async () => {
    get.mockResolvedValueOnce({ rolls: [], page: 2, hasMore: false });
    expect(await rollHistoryPage(3, 2)).toEqual({ rolls: [], page: 2, hasMore: false });
    expect(get).toHaveBeenCalledWith("/scenes/3/rolls?page=2");
  });
});
