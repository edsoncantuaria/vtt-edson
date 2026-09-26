import type { CatalogEntry } from "./catalog";

export type OriginChoice = {
  id: string;
  label: string;
  fixed: string[];
  options: string[];
  count: number;
  unsupported: boolean;
};

/** Only interpret explicit catalogue choices. An unbounded `any` is not silently fulfilled. */
export function originChoices(
  race: CatalogEntry | null,
  background: CatalogEntry | null,
  subrace: CatalogEntry | null = null,
): OriginChoice[] {
  return [race, subrace, background].flatMap((entry, index) => {
    if (!entry) return [];
    return (["languageProficiencies", "toolProficiencies", "skillProficiencies"] as const).flatMap(
      (field) => {
        const rows = entry.data.raw[field];
        if (!Array.isArray(rows)) return [];
        const row = rows[0];
        if (!row || typeof row !== "object") return [];
        const choose =
          row.choose && typeof row.choose === "object"
            ? (row.choose as Record<string, unknown>)
            : null;
        const options =
          choose && Array.isArray(choose.from)
            ? choose.from.filter((value): value is string => typeof value === "string")
            : [];
        const count =
          choose && Number.isInteger(choose.count) ? Number(choose.count) : choose ? 1 : 0;
        const fixed = Object.entries(row)
          .filter(([key, value]) => key !== "choose" && value === true)
          .map(([key]) => key);
        const unsupported =
          Object.keys(row).some((key) => key.startsWith("any") && row[key] !== false) ||
          (!!choose && (!options.length || count < 1));
        return [
          {
            id: `${["race", "subrace", "background"][index]}-${field}`,
            label: `${["Espécie", "Sub-raça", "Antecedente"][index]} · ${field === "languageProficiencies" ? "idiomas" : field === "toolProficiencies" ? "ferramentas" : "perícias"}`,
            fixed,
            options,
            count,
            unsupported,
          },
        ];
      },
    );
  });
}

export function originChoiceIssues(
  rules: OriginChoice[],
  selected: Record<string, string[]>,
): string[] {
  return rules.flatMap((rule) => {
    const values = selected[rule.id] ?? [];
    if (rule.unsupported)
      return [`${rule.label}: escolha aberta no catálogo, exige revisão do mestre.`];
    if (
      values.length !== rule.count ||
      new Set(values).size !== values.length ||
      values.some((value) => !rule.options.includes(value))
    ) {
      return [`${rule.label}: escolha ${rule.count} opção(ões) permitida(s).`];
    }
    return [];
  });
}

export function requiresOriginFeat(background: CatalogEntry | null): boolean {
  return (
    Array.isArray(background?.data.raw.feats) &&
    background.data.raw.feats.some((feat) =>
      Object.keys(feat).some((key) => key === "any" || key === "choose"),
    )
  );
}

export function fixedOriginFeats(background: CatalogEntry | null): string[] {
  return (background?.data.raw.feats ?? []).flatMap((feat) =>
    Object.entries(feat)
      .filter(([key, value]) => !["any", "choose"].includes(key) && value === true)
      .map(([key]) => key.split("|")[0]),
  );
}
