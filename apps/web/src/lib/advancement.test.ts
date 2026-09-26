import { beforeEach, describe, expect, it, vi } from "vitest";
import { emptyActorSystem } from "@vtt/core";
import { api } from "./api";
import { confirmActorAdvancement, previewActorAdvancement } from "./advancement";

vi.mock("./api", () => ({ api: vi.fn() }));

const apiMock = vi.mocked(api);

const request = {
  requestId: "43fa9151-0e32-43e5-90ef-cbc4c5fe0c10",
  revision: 4,
  classId: 12,
  targetLevel: 2,
  system: emptyActorSystem(),
};

describe("character level-up API contract", () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it("posts a non-mutating preview then confirms through the same endpoint and request ID", async () => {
    apiMock.mockResolvedValueOnce({ preview: { level: [1, 2] }, revision: 4 });
    apiMock.mockResolvedValueOnce({ actor: { id: 9 }, alreadyApplied: false, advancementId: 3 });

    const preview = await previewActorAdvancement(9, request);
    expect(preview.preview.level).toEqual([1, 2]);
    await confirmActorAdvancement(9, request);

    expect(apiMock).toHaveBeenCalledTimes(2);
    expect(apiMock.mock.calls.map(([url, options]) => [url, options?.method])).toEqual([
      ["/actors/9/advancements", "POST"],
      ["/actors/9/advancements", "POST"],
    ]);
    const previewBody = JSON.parse(String(apiMock.mock.calls[0][1]?.body));
    const confirmationBody = JSON.parse(String(apiMock.mock.calls[1][1]?.body));
    expect(previewBody).toEqual({ ...request, preview: true });
    expect(confirmationBody).toEqual(request);
    expect(previewBody.requestId).toBe(confirmationBody.requestId);
    expect(previewBody.revision).toBe(confirmationBody.revision);
  });

  it("retries an ambiguous confirmation with the same idempotency key", async () => {
    apiMock.mockRejectedValueOnce(new Error("Connection lost"));
    apiMock.mockResolvedValueOnce({ actor: { id: 9 }, alreadyApplied: true, advancementId: 3 });
    await expect(confirmActorAdvancement(9, request)).rejects.toThrow("Connection lost");
    const retry = await confirmActorAdvancement(9, request);
    expect(retry.alreadyApplied).toBe(true);
    expect(apiMock.mock.calls[0][1]?.body).toBe(apiMock.mock.calls[1][1]?.body);
  });
});
