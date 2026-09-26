import type { Actor, ChatMessage, SceneState } from "@vtt/core";
import { updateScene } from "./scene";
import type { SpellCastChoice } from "./spellcasting";

/** Shared action entrypoint for sheets, spell buttons and the selected-actor hotbar. */
export function executeActorAction(
  sceneId: number,
  actor: Actor,
  actionId: string,
  requestId: string,
  mode: string,
  selectedTargetIds: number[],
  selectedTokenIds: string[] = [],
  spellCast?: SpellCastChoice,
): Promise<{ message: ChatMessage; actor: Actor; state: SceneState }> {
  const guided = actionId.startsWith("spell:");
  const action = actor.system.actions.find((entry) => entry.id === actionId);
  if (!action && !guided) throw new Error("A ação não existe mais nesta ficha.");
  const targetActorIds = action?.target === "self" ? [actor.id] : [...new Set(selectedTargetIds)];
  const targetTokenIds = action?.target === "self" ? [] : [...new Set(selectedTokenIds)];
  return updateScene(sceneId, "/actions", {
    actorId: actor.id,
    actionId,
    requestId,
    mode,
    ...(spellCast ? { spellCast } : {}),
    ...(targetTokenIds.length ? { targetTokenIds } : { targetActorIds }),
  }) as Promise<{ message: ChatMessage; actor: Actor; state: SceneState }>;
}
