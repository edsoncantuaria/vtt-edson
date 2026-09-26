import type { SceneState } from "@vtt/core";

/** Targets reference visible token instances, not readable actor documents. */
export function reconcileTargetTokens(state: SceneState, tokenIds: string[]): string[] {
  const visible = new Set(state.tokens.map((token) => token.id));
  return [...new Set(tokenIds)].filter((id) => visible.has(id));
}

export function toggleTargetToken(
  state: SceneState,
  selected: string[],
  tokenId: string,
): string[] {
  const current = reconcileTargetTokens(state, selected);
  if (!state.tokens.some((token) => token.id === tokenId)) return current;
  return current.includes(tokenId) ? current.filter((id) => id !== tokenId) : [...current, tokenId];
}

/** Legacy actor-scoped consumers receive only IDs the scene already disclosed. */
export function readableTargetActors(state: SceneState, tokenIds: string[]): number[] {
  const targets = new Set(reconcileTargetTokens(state, tokenIds));
  return [
    ...new Set(
      state.tokens
        .filter((token) => targets.has(token.id) && typeof token.actorId === "number")
        .map((token) => token.actorId as number),
    ),
  ];
}

export function isTargetGesture(input: {
  targetingMode: boolean;
  modifier: boolean;
  canEditScene: boolean;
  ownToken: boolean;
}): boolean {
  return input.targetingMode || input.modifier || (!input.canEditScene && !input.ownToken);
}
