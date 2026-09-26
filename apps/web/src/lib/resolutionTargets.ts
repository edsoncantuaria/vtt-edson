import type { Actor, ChatMessage, Role } from "@vtt/core";
import { isManagerRole } from "@vtt/core";

/** A persisted action's targets outrank a later, unrelated map selection. */
export function resolutionTargets(
  actors: Actor[],
  role: Role | null,
  viewerId: number | undefined,
  message: ChatMessage,
  liveTargetIds: number[],
) {
  const fixedTargets = message.targetActorIds ?? [];
  const editable = actors.filter(
    (actor) =>
      (isManagerRole(role) || actor.ownerUserId === viewerId) &&
      (!message.targetMode || fixedTargets.includes(actor.id)),
  );
  const suggested = fixedTargets.length ? fixedTargets : !message.targetMode ? liveTargetIds : [];
  const mapTargets = suggested
    .map((id) => editable.find((actor) => actor.id === id))
    .filter((actor): actor is Actor => !!actor);

  return { editable, fixedTargets, mapTargets };
}
