import type { Actor, Role } from "@vtt/core";
import { isManagerRole } from "@vtt/core";

export function healingTargets(
  actors: Actor[],
  ids: (number | undefined)[],
  role: Role | null,
  userId?: number,
): Actor[] {
  if (!role || role === "observer") return [];
  return actors.filter(
    (actor) => ids.includes(actor.id) && (isManagerRole(role) || actor.ownerUserId === userId),
  );
}
