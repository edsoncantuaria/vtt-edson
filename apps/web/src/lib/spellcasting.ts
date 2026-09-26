import type { Actor } from "@vtt/core";

export type SpellOption = {
  actionId: string;
  documentId: number;
  name: string;
  source: string;
  edition: "5e-2014" | "5e-2024";
  level: number;
  kind: "missiles" | "area-save" | "heal" | "catalog-action";
  prepared: boolean;
  requiresPreparation: boolean;
  ritual: boolean;
  ritualWithoutPreparation: boolean;
  components: { v: boolean; s: boolean; m: unknown };
  rangeFeet: number;
  concentration: boolean;
  areaFeet: number | null;
  target?: "self" | "single" | "multiple" | "area";
  maxTargets?: number | null;
  canCast: boolean;
  canRitual: boolean;
};

export type SpellCastChoice = {
  slotLevel?: number;
  ritual?: boolean;
  componentsConfirmed?: boolean;
  missiles?: Array<{ tokenId: string; count: number }>;
  centerTokenId?: string;
};

/** The frontend only offers valid choices; the server re-checks before any roll or cost. */
export function availableSpellSlots(actor: Actor, spell: SpellOption): number[] {
  if (spell.level === 0) return [];
  return Object.entries(actor.system.spells.slots)
    .filter(
      ([level, slot]) => Number(level) >= spell.level && Number(level) <= 9 && slot.used < slot.max,
    )
    .map(([level]) => Number(level))
    .sort((a, b) => a - b);
}

export function missileCount(spell: SpellOption, slotLevel: number): number {
  return 3 + Math.max(0, slotLevel - spell.level);
}

export function missileAllocations(
  tokens: string[],
  counts: Record<string, number>,
  expected: number,
): Array<{ tokenId: string; count: number }> | null {
  const selected = [...new Set(tokens)];
  if (
    !selected.length ||
    selected.some((id) => !Number.isInteger(counts[id]) || counts[id] < 1) ||
    selected.reduce((sum, id) => sum + counts[id], 0) !== expected
  )
    return null;
  return selected.map((tokenId) => ({ tokenId, count: counts[tokenId] }));
}

export function componentSummary(spell: SpellOption): string {
  const components = [
    spell.components.v && "V",
    spell.components.s && "S",
    spell.components.m && "M",
  ]
    .filter(Boolean)
    .join(", ");
  const material =
    typeof spell.components.m === "string"
      ? ` (${spell.components.m})`
      : spell.components.m && typeof spell.components.m === "object" && "text" in spell.components.m
        ? ` (${String(spell.components.m.text)})`
        : "";
  return `${components || "Nenhum componente registrado"}${material}`;
}
