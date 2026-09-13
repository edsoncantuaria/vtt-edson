import type { Drawing, Region, SceneLabel, SceneState, Tile } from "@vtt/core";

export type CanvasObjectKind = "drawings" | "labels" | "tiles" | "regions";
export type CanvasObject = Drawing | SceneLabel | Tile | Region;
export type CanvasSelection = { kind: CanvasObjectKind; id: string };

export function canvasObject(
  state: SceneState,
  selection: CanvasSelection | null,
): CanvasObject | null {
  if (!selection) return null;
  return state[selection.kind].find((item) => item.id === selection.id) ?? null;
}

export function canvasPayload(
  kind: CanvasObjectKind,
  object: CanvasObject,
): Record<string, unknown> {
  if (kind === "drawings") {
    const drawing = object as Drawing;
    return {
      id: drawing.id,
      kind: drawing.kind,
      points: drawing.points,
      stroke: drawing.stroke,
      width: drawing.width,
      fill: drawing.fill ?? null,
      hidden: drawing.hidden,
    };
  }
  if (kind === "labels") {
    const label = object as SceneLabel;
    return {
      id: label.id,
      x: label.x,
      y: label.y,
      text: label.text,
      fontSize: label.fontSize,
      hidden: label.hidden,
    };
  }
  if (kind === "tiles") {
    const tile = object as Tile;
    return {
      id: tile.id,
      assetId: tile.assetId,
      x: tile.x,
      y: tile.y,
      w: tile.w,
      h: tile.h,
      opacity: tile.opacity,
      rotation: tile.rotation,
      hidden: tile.hidden,
    };
  }
  const region = object as Region;
  return {
    id: region.id,
    x: region.x,
    y: region.y,
    w: region.w,
    h: region.h,
    name: region.name,
    behavior: region.behavior,
    note: region.note ?? null,
    hidden: region.hidden,
  };
}

export function translateCanvasObject(
  kind: CanvasObjectKind,
  object: CanvasObject,
  dx: number,
  dy: number,
): CanvasObject {
  if (kind === "drawings") {
    const drawing = object as Drawing;
    return {
      ...drawing,
      points: drawing.points.map((point) => ({ x: point.x + dx, y: point.y + dy })),
    };
  }
  return {
    ...object,
    x: (object as SceneLabel | Tile | Region).x + dx,
    y: (object as SceneLabel | Tile | Region).y + dy,
  } as CanvasObject;
}
