import type { Actor } from "@vtt/core";

export const CONDITION_LABELS: Record<string, { label: string; short: string }> = {
  poisoned: { label: "Envenenado", short: "ENV" },
  restrained: { label: "Contido", short: "CON" },
  prone: { label: "Caído", short: "CAI" },
  blinded: { label: "Cego", short: "CEG" },
  charmed: { label: "Enfeitiçado", short: "ENC" },
  deafened: { label: "Surdo", short: "SUR" },
  frightened: { label: "Amedrontado", short: "MED" },
  grappled: { label: "Agarrado", short: "AGA" },
  incapacitated: { label: "Incapacitado", short: "INC" },
  invisible: { label: "Invisível", short: "INV" },
  paralyzed: { label: "Paralisado", short: "PAR" },
  petrified: { label: "Petrificado", short: "PET" },
  stunned: { label: "Atordoado", short: "ATO" },
  unconscious: { label: "Inconsciente", short: "INS" },
  exhaustion: { label: "Exausto", short: "EXA" },
};

const aliases: Record<string, string> = {
  envenenado: "poisoned",
  contido: "restrained",
  caido: "prone",
  cego: "blinded",
  paralisado: "paralyzed",
  atordoado: "stunned",
  inconsciente: "unconscious",
  agarrado: "grappled",
};

export function normalizeCondition(value: string): string {
  const key = value
    .trim()
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");
  return aliases[key] ?? key;
}

/** Only already-authorized effects are provided by the actor API. */
export function actorConditions(actor: Actor): string[] {
  return [
    ...new Set(
      [
        ...(actor.system.conditions ?? []),
        ...actor.activeEffects
          .filter((effect) => effect.active)
          .flatMap((effect) => effect.conditions),
      ].map(normalizeCondition),
    ),
  ].filter(Boolean);
}

export function conditionLabel(condition: string): string {
  return CONDITION_LABELS[normalizeCondition(condition)]?.label ?? condition;
}
