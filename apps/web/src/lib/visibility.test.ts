import { describe, expect, it } from "vitest";
import { pointInAreaTemplate, segmentIntersectsSegment, visibilityPolygon } from "./visibility";

describe("visibilityPolygon", () => {
  it("stops rays at a wall", () => {
    const polygon = visibilityPolygon({ x: 0, y: 0 }, 100, [{ x1: 30, y1: -50, x2: 30, y2: 50 }]);
    expect(polygon.some((point) => point.x > 30.01 && Math.abs(point.y) < 45)).toBe(false);
  });
});

describe("map geometry", () => {
  it("detects crossing segments", () => {
    expect(
      segmentIntersectsSegment(
        { x1: 0, y1: 0, x2: 100, y2: 0 },
        { x1: 50, y1: -20, x2: 50, y2: 20 },
      ),
    ).toBe(true);
    expect(
      segmentIntersectsSegment(
        { x1: 0, y1: 0, x2: 10, y2: 0 },
        { x1: 50, y1: -20, x2: 50, y2: 20 },
      ),
    ).toBe(false);
  });

  it("hit-tests circle, line and cone templates", () => {
    expect(
      pointInAreaTemplate({ x: 30, y: 0 }, "circle", { x: 0, y: 0 }, { x: 40, y: 0 }, 10),
    ).toBe(true);
    expect(pointInAreaTemplate({ x: 30, y: 4 }, "line", { x: 0, y: 0 }, { x: 40, y: 0 }, 10)).toBe(
      true,
    );
    expect(pointInAreaTemplate({ x: 30, y: 10 }, "cone", { x: 0, y: 0 }, { x: 40, y: 0 }, 10)).toBe(
      true,
    );
    expect(pointInAreaTemplate({ x: 20, y: 20 }, "cone", { x: 0, y: 0 }, { x: 40, y: 0 }, 10)).toBe(
      false,
    );
  });
});
