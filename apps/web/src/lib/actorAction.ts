import type { Actor, ChatMessage, SceneState } from "@vtt/core";
import { updateScene } from "./scene";

/** Shared action entrypoint for sheets, spell buttons and the selected-actor hotbar. */
export function executeActorAction(
  sceneId: number,
  actor: Actor,
  actionId: string,
  requestId: string,
  mode: string,
  selectedTargetIds: number[],
): Promise<{ message: ChatMessage; actor: Actor; state: SceneState }> {
  const action = actor.system.actions.find((entry) => entry.id === actionId);
  if (!action) throw new Error("A ação não existe mais nesta ficha.");
  const targetActorIds = action.target === "self" ? [actor.id] : [...new Set(selectedTargetIds)];
  return updateScene(sceneId, "/actions", {
    actorId: actor.id,
    actionId,
    requestId,
    mode,
    targetActorIds,
  }) as Promise<{ message: ChatMessage; actor: Actor; state: SceneState }>;
}
