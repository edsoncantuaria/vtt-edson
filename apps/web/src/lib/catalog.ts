import type { Ability } from "@vtt/core";

export type CatalogKind =
  | "spells"
  | "items"
  | "monsters"
  | "classes"
  | "subclasses"
  | "races"
  | "backgrounds"
  | "feats"
  | "features"
  | "rules"
  | "books"
  | "adventures"
  | "bastions"
  | "vehicles"
  | "decks"
  | "recipes"
  | "psionics"
  | "rewards"
  | "deities"
  | "languages"
  | "hazards"
  | "objects"
  | "cults";

type MulticlassRequirements = Partial<Record<Ability, number>> & {
  or?: Array<Partial<Record<Ability, number>>>;
};

/**
 * Stable subset of the heterogeneous 5etools payload consumed by the UI.
 * Unknown upstream keys stay available as `unknown` and must be narrowed at
 * the feature boundary instead of propagating `any` through the application.
 */
export type FiveToolsRaw = Record<string, unknown> & {
  ability?: unknown[];
  casterProgression?: string;
  cantripProgression?: number[];
  className?: string;
  classSource?: string;
  classTableGroups?: Array<{ rowsSpellProgression?: number[][] }>;
  darkvision?: number;
  hd?: { faces?: number };
  multiclassing?: {
    requirements?: MulticlassRequirements;
    requirementsSpecial?: unknown;
    proficienciesGained?: {
      armor?: unknown[];
      weapons?: unknown[];
      tools?: unknown[];
    };
  };
  preparedSpells?: string;
  preparedSpellsProgression?: number[];
  proficiency?: string[];
  skillProficiencies?: Array<Record<string, unknown>>;
  speed?: number | { walk?: number };
  spellcastingAbility?: Ability;
  spellsKnownProgression?: number[];
  startingEquipment?: unknown[] | (Record<string, unknown> & { defaultData?: unknown[] });
  startingProficiencies?: {
    armor?: unknown[];
    weapons?: unknown[];
    skills?: Array<{ choose?: { count: number; from: string[] } }>;
  };
  subclassFeatures?: unknown[];
};

export type CatalogData = Record<string, unknown> & {
  raw: FiveToolsRaw;
  description?: string;
  desc?: unknown;
  challenge_rating?: unknown;
  currency?: unknown;
  damage?: unknown;
  effect?: unknown;
  images?: unknown;
  items?: unknown;
  length?: unknown;
  level?: unknown;
  levelFeatures?: Array<{ name: string; level: number; description?: string }>;
  map?: unknown;
  page?: string | number;
  sourceName?: string;
  spellLists?: string[];
  tokenUrl?: string;
  type?: unknown;
  abilities?: Record<string, number>;
  strength?: number;
  dexterity?: number;
  constitution?: number;
  intelligence?: number;
  wisdom?: number;
  charisma?: number;
  hp?: string | number;
  hit_points?: number;
  armor_class?: number;
  ac?: number;
  speed?: string | { walk?: number };
  actions?: Array<{ name?: string; desc?: string }>;
  size?: unknown;
  alignment?: unknown;
};

export type CatalogEntry = {
  id: number;
  shared?: boolean;
  slug: string;
  kind: CatalogKind;
  name: string;
  source: string;
  edition: string;
  level?: number;
  data: CatalogData;
};

export type HomebrewCatalogEntry = {
  homebrewId: number;
  packageId: number;
  slug: string;
  kind: CatalogKind;
  name: string;
  version: number;
  data: CatalogData;
  package?: { name: string; version: number };
};

export type CatalogResults = {
  data: CatalogEntry[];
  homebrew?: HomebrewCatalogEntry[];
  current_page: number;
  last_page: number;
  total: number;
  sources: string[];
};

export const CATALOG_KINDS: { id: CatalogKind; label: string; optional?: boolean }[] = [
  { id: "spells", label: "Magias" },
  { id: "items", label: "Equipamentos" },
  { id: "monsters", label: "Criaturas" },
  { id: "classes", label: "Classes" },
  { id: "subclasses", label: "Subclasses" },
  { id: "races", label: "Espécies / raças" },
  { id: "backgrounds", label: "Antecedentes" },
  { id: "feats", label: "Talentos" },
  { id: "features", label: "Características" },
  { id: "rules", label: "Regras" },
  { id: "books", label: "Livros" },
  { id: "adventures", label: "Aventuras" },
  { id: "bastions", label: "Bastiões", optional: true },
  { id: "vehicles", label: "Veículos", optional: true },
  { id: "decks", label: "Baralhos", optional: true },
  { id: "recipes", label: "Receitas", optional: true },
  { id: "psionics", label: "Psiônicos", optional: true },
  { id: "rewards", label: "Recompensas", optional: true },
  { id: "deities", label: "Divindades", optional: true },
  { id: "languages", label: "Idiomas", optional: true },
  { id: "hazards", label: "Armadilhas e perigos", optional: true },
  { id: "objects", label: "Objetos", optional: true },
  { id: "cults", label: "Cultos e dádivas", optional: true },
];
