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
  selectedTokenIds: string[] = [],
): Promise<{ message: ChatMessage; actor: Actor; state: SceneState }> {
  const action = actor.system.actions.find((entry) => entry.id === actionId);
  if (!action) throw new Error("A ação não existe mais nesta ficha.");
  const targetActorIds = action.target === "self" ? [actor.id] : [...new Set(selectedTargetIds)];
  const targetTokenIds = action.target === "self" ? [] : [...new Set(selectedTokenIds)];
  return updateScene(sceneId, "/actions", {
    actorId: actor.id,
    actionId,
    requestId,
    mode,
    ...(targetTokenIds.length ? { targetTokenIds } : { targetActorIds }),
  }) as Promise<{ message: ChatMessage; actor: Actor; state: SceneState }>;
}
