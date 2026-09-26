import type { Actor, ActorAction } from "@vtt/core";

/** UI hint only: the server always quotes and locks every cost before rolling. */
export function unavailableResource(actor: Actor, action: ActorAction): string | null {
  if (action.spellSlotLevel != null) {
    const slot = actor.system.spells.slots[String(action.spellSlotLevel)];
    if (!slot || slot.used >= slot.max) return `Espaço de nível ${action.spellSlotLevel} esgotado.`;
  }
  if (action.resourceId) {
    if (action.resourceId === "inspiration") {
      if (!actor.system.inspiration) return "Inspiração indisponível.";
    } else {
      const pool = actor.system.resources.find((entry) => entry.id === action.resourceId);
      if (!pool) return "Recurso não existe mais na ficha.";
      const cost = action.resourceCost ?? pool.defaultCost ?? 1;
      if (pool.max - pool.used < cost)
        return `${pool.name}: requer ${cost}, disponíveis ${pool.max - pool.used}.`;
    }
  }
  if (action.documentId) {
    const document = actor.documents.find((entry) => entry.id === action.documentId);
    if (!document?.charges || document.charges.value < (action.chargeCost ?? 1))
      return `Cargas insuficientes no item ${document?.name ?? "configurado"}.`;
  }
  return null;
}
