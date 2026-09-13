export type Point = { x: number; y: number };
export type Segment = { x1: number; y1: number; x2: number; y2: number };

export type TemplateKind = "circle" | "cone" | "line" | "radius";

const EPSILON = 0.000001;

function raySegment(origin: Point, direction: Point, wall: Segment): Point | null {
  const rX = direction.x - origin.x;
  const rY = direction.y - origin.y;
  const sX = wall.x2 - wall.x1;
  const sY = wall.y2 - wall.y1;
  const divisor = rX * sY - rY * sX;
  if (Math.abs(divisor) < EPSILON) return null;
  const qX = wall.x1 - origin.x;
  const qY = wall.y1 - origin.y;
  const t = (qX * sY - qY * sX) / divisor;
  const u = (qX * rY - qY * rX) / divisor;
  return t >= 0 && t <= 1 && u >= 0 && u <= 1
    ? { x: origin.x + rX * t, y: origin.y + rY * t }
    : null;
}

export function segmentIntersectsSegment(a: Segment, b: Segment): boolean {
  const rX = a.x2 - a.x1;
  const rY = a.y2 - a.y1;
  const sX = b.x2 - b.x1;
  const sY = b.y2 - b.y1;
  const qX = b.x1 - a.x1;
  const qY = b.y1 - a.y1;
  const divisor = rX * sY - rY * sX;
  if (Math.abs(divisor) < EPSILON) {
    if (Math.abs(qX * rY - qY * rX) > EPSILON) return false;
    const length = rX * rX + rY * rY;
    if (length < EPSILON) return false;
    const t1 = (qX * rX + qY * rY) / length;
    const t2 = ((b.x2 - a.x1) * rX + (b.y2 - a.y1) * rY) / length;
    return Math.max(t1, t2) >= 0 && Math.min(t1, t2) <= 1;
  }
  const t = (qX * sY - qY * sX) / divisor;
  const u = (qX * rY - qY * rX) / divisor;
  return t >= 0 && t <= 1 && u >= 0 && u <= 1;
}

function pointToSegmentDistance(point: Point, start: Point, end: Point): number {
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const length = dx * dx + dy * dy;
  if (length < EPSILON) return Math.hypot(point.x - start.x, point.y - start.y);
  const t = Math.max(
    0,
    Math.min(1, ((point.x - start.x) * dx + (point.y - start.y) * dy) / length),
  );
  return Math.hypot(point.x - (start.x + t * dx), point.y - (start.y + t * dy));
}

/** Center-point hit test for D&D-style map templates. Lines are one grid cell wide; cones widen to their range. */
export function pointInAreaTemplate(
  point: Point,
  kind: TemplateKind,
  origin: Point,
  end: Point,
  gridSize: number,
): boolean {
  const dx = end.x - origin.x;
  const dy = end.y - origin.y;
  const radius = Math.hypot(dx, dy);
  if (radius < EPSILON) return false;
  if (kind === "circle" || kind === "radius") {
    return Math.hypot(point.x - origin.x, point.y - origin.y) <= radius + EPSILON;
  }
  if (kind === "line") {
    return pointToSegmentDistance(point, origin, end) <= gridSize / 2 + EPSILON;
  }
  const px = point.x - origin.x;
  const py = point.y - origin.y;
  const distance = Math.hypot(px, py);
  if (distance > radius + EPSILON || distance < EPSILON) return false;
  const dot = (px * dx + py * dy) / (distance * radius);
  // 5e cones are as wide as their range, giving a ~53.13° full angle.
  return dot >= Math.cos(Math.atan(0.5)) - EPSILON;
}

/** Rays to wall ends (plus a ring) create a stable, bounded line-of-sight polygon. */
export function visibilityPolygon(origin: Point, radius: number, walls: Segment[]): Point[] {
  const angles = Array.from({ length: 40 }, (_, index) => (index / 40) * Math.PI * 2);
  for (const wall of walls) {
    for (const end of [
      { x: wall.x1, y: wall.y1 },
      { x: wall.x2, y: wall.y2 },
    ]) {
      const angle = Math.atan2(end.y - origin.y, end.x - origin.x);
      angles.push(angle - 0.0001, angle, angle + 0.0001);
    }
  }
  return angles
    .map((angle) => {
      const edge = {
        x: origin.x + Math.cos(angle) * radius,
        y: origin.y + Math.sin(angle) * radius,
      };
      let closest = edge;
      let distance = radius;
      for (const wall of walls) {
        const hit = raySegment(origin, edge, wall);
        if (!hit) continue;
        const hitDistance = Math.hypot(hit.x - origin.x, hit.y - origin.y);
        if (hitDistance < distance) {
          closest = hit;
          distance = hitDistance;
        }
      }
      return { angle, point: closest };
    })
    .sort((a, b) => a.angle - b.angle)
    .map(({ point }) => point);
}
