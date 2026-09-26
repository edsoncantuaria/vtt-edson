import type { ActorAction } from "@vtt/core";

export type CustomActionPreset = "attack" | "torch" | "maneuver" | "potion" | "feature";

/** User-owned copies never inherit the reserved document: identifier. */
export function duplicateActorAction(action: ActorAction, id: string): ActorAction {
  return {
    ...structuredClone(action),
    id,
    name: `${action.name} (cópia)`,
    origin: `Personalizada · cópia de ${action.origin || action.name}`.slice(0, 160),
    visibility: "public",
    // A copy is independent: explicitly reselect the original document if its
    // charges should still be consumed, rather than retaining a stale binding.
    documentId: undefined,
    chargeCost: undefined,
  };
}

export function createCustomAction(preset: CustomActionPreset, id: string): ActorAction {
  const base = { id, origin: "Mesa · configuração manual", visibility: "public" as const };
  switch (preset) {
    case "torch":
      return {
        ...base,
        name: "Ataque com tocha",
        kind: "attack",
        attackFormula: "1d20+0",
        damageFormula: "1d4",
        damageType: "fire",
        target: "single",
        economy: "action",
      };
    case "maneuver":
      return {
        ...base,
        name: "Manobra",
        kind: "feature",
        attackFormula: "1d20+0",
        damageFormula: "1d8",
        economy: "action",
        target: "single",
      };
    case "potion":
      return {
        ...base,
        name: "Poção de cura",
        kind: "item",
        healingFormula: "2d4+2",
        target: "single",
        economy: "action",
      };
    case "feature":
      return {
        ...base,
        name: "Habilidade personalizada",
        kind: "feature",
        target: "self",
        economy: "bonus",
        resourceCost: undefined,
      };
    default:
      return {
        ...base,
        name: "Novo ataque",
        kind: "attack",
        attackFormula: "1d20+0",
        target: "single",
        economy: "action",
      };
  }
}
