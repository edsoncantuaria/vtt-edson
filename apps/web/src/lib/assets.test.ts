import { describe, expect, it } from "vitest";
import { publicAssetUrl } from "./assets";
describe("Laravel map URLs", () => {
  it("loads uploaded maps through the same origin even when APP_URL differs from the frontend", () => {
    expect(publicAssetUrl("http://localhost:8000/storage/scenes/1/map.png")).toBe(
      "/storage/scenes/1/map.png",
    );
    expect(publicAssetUrl("https://api.example.test/storage/scenes/1/map.webp?v=2")).toBe(
      "/storage/scenes/1/map.webp?v=2",
    );
  });
  it("preserves other assets and missing backgrounds", () => {
    expect(publicAssetUrl("/watchtower-map.png")).toBe("/watchtower-map.png");
    expect(publicAssetUrl("https://cdn.example.test/maps/forest.webp")).toBe(
      "https://cdn.example.test/maps/forest.webp",
    );
    expect(publicAssetUrl(null)).toBeNull();
  });
});
