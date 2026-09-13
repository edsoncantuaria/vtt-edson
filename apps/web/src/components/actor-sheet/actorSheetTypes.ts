export const ACTOR_SHEET_TABS = [
  "Atributos",
  "Ações",
  "Equipamento",
  "Magias",
  "História",
] as const;

export type ActorSheetTab = (typeof ACTOR_SHEET_TABS)[number];
