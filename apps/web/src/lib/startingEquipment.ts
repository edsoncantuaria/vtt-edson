import type { ActorSystem } from "@vtt/core";
import type { CatalogEntry } from "./catalog";

export type StartingEquipmentItem = {
  ref: string;
  name: string;
  source?: string;
  quantity: number;
};

export type StartingEquipmentOption = {
  key: string;
  label: string;
  items: StartingEquipmentItem[];
  manualTypes: string[];
  valueCopper: number;
};

export type StartingEquipmentGroup = {
  id: string;
  options: StartingEquipmentOption[];
};

export type StartingEquipmentPlan = {
  groups: StartingEquipmentGroup[];
  structured: boolean;
};

function itemFromRef(
  ref: string,
  quantity = 1,
  displayName?: string,
): StartingEquipmentItem | null {
  const clean = ref.trim();
  if (!clean) return null;
  const [name, source] = clean.split("|");
  if (!name) return null;
  return {
    ref: clean,
    name: displayName?.trim() || name,
    source: source || undefined,
    quantity: Math.max(1, quantity),
  };
}

function parseEntries(value: unknown): Omit<StartingEquipmentOption, "key" | "label"> {
  const entries = Array.isArray(value) ? value : [value];
  const items: StartingEquipmentItem[] = [];
  const manualTypes: string[] = [];
  let valueCopper = 0;
  for (const entry of entries) {
    if (typeof entry === "string") {
      const item = itemFromRef(entry);
      if (item) items.push(item);
      continue;
    }
    if (!entry || typeof entry !== "object") continue;
    const row = entry as Record<string, unknown>;
    if (typeof row.item === "string") {
      const item = itemFromRef(
        row.item,
        typeof row.quantity === "number" ? row.quantity : 1,
        typeof row.displayName === "string" ? row.displayName : undefined,
      );
      if (item) items.push(item);
    }
    if (typeof row.equipmentType === "string") manualTypes.push(row.equipmentType);
    if (typeof row.special === "string") manualTypes.push(row.special);
    if (typeof row.containsValue === "number")
      manualTypes.push(`conteúdo monetário de ${row.containsValue} cp`);
    if (typeof row.value === "number" && Number.isFinite(row.value) && row.value > 0)
      valueCopper += Math.round(row.value);
  }
  return { items, manualTypes, valueCopper };
}

function labelFor(key: string, parsed: Omit<StartingEquipmentOption, "key" | "label">): string {
  const parts = [
    ...parsed.items.map((item) => `${item.quantity > 1 ? item.quantity + "× " : ""}${item.name}`),
    ...parsed.manualTypes.map((type) => `escolha: ${type}`),
  ];
  if (parsed.valueCopper > 0)
    parts.push(`${(parsed.valueCopper / 100).toLocaleString("pt-BR")} PO`);
  return `${key === "_" ? "Padrão" : `Opção ${key.toUpperCase()}`}: ${parts.join(", ") || "revisar no livro"}`;
}

export function startingEquipmentPlan(cls: CatalogEntry): StartingEquipmentPlan {
  const starting = cls.data.raw?.startingEquipment;
  const raw = Array.isArray(starting) ? starting : starting?.defaultData;
  if (!Array.isArray(raw) || raw.length === 0) return { groups: [], structured: false };
  const groups: StartingEquipmentGroup[] = raw.map((group: unknown, index: number) => {
    if (!group || typeof group !== "object" || Array.isArray(group)) {
      const parsed = parseEntries(group);
      return {
        id: String(index),
        options: [{ key: "_", label: labelFor("_", parsed), ...parsed }],
      };
    }
    const record = group as Record<string, unknown>;
    const optionKeys = Object.keys(record).filter((key) => key === "_" || /^[A-Za-z]$/.test(key));
    if (!optionKeys.length) {
      const parsed = parseEntries(group);
      return {
        id: String(index),
        options: [{ key: "_", label: labelFor("_", parsed), ...parsed }],
      };
    }
    return {
      id: String(index),
      options: optionKeys.map((key) => {
        const parsed = parseEntries(record[key]);
        return { key, label: labelFor(key, parsed), ...parsed };
      }),
    };
  });
  return { groups, structured: true };
}

export function hasStructuredStartingEquipment(entry: CatalogEntry): boolean {
  return startingEquipmentPlan(entry).structured;
}

export function combineStartingEquipmentPlans(
  ...plans: StartingEquipmentPlan[]
): StartingEquipmentPlan {
  const active = plans.filter((plan) => plan.structured);
  return {
    structured: active.length > 0,
    groups: active.flatMap((plan, planIndex) =>
      plan.groups.map((group) => ({
        ...group,
        id: `${planIndex}:${group.id}`,
      })),
    ),
  };
}

export function resolveStartingEquipment(
  plan: StartingEquipmentPlan,
  selections: Record<string, string>,
  manualItems: CatalogEntry[],
): {
  inventory: ActorSystem["inventory"];
  currencyGp: number;
  currencyCp: number;
  manualTypes: string[];
  issues: string[];
  complete: boolean;
} {
  const inventory: ActorSystem["inventory"] = [];
  const issues: string[] = [];
  let valueCopper = 0;
  const manualTypes: string[] = [];
  for (const [groupIndex, group] of plan.groups.entries()) {
    const selectedKey =
      selections[group.id] ?? (group.options.length === 1 ? group.options[0].key : undefined);
    const option = selectedKey
      ? group.options.find((candidate) => candidate.key === selectedKey)
      : undefined;
    if (!option) {
      issues.push(`Escolha uma opção de equipamento no grupo ${groupIndex + 1}.`);
      continue;
    }
    for (const item of option.items) {
      inventory.push({
        id: `starting:${item.ref}`,
        slug: null,
        name: item.name,
        quantity: item.quantity,
        equipped: false,
        description: item.source ? `Equipamento inicial · ${item.source}` : "Equipamento inicial",
      });
    }
    valueCopper += option.valueCopper;
    manualTypes.push(...option.manualTypes);
  }
  if (manualItems.length < manualTypes.length) {
    for (const type of manualTypes.slice(manualItems.length))
      issues.push(`Escolha manualmente no catálogo: ${type}.`);
  }
  for (const item of manualItems.slice(0, manualTypes.length)) {
    inventory.push({
      id: item.slug,
      slug: item.slug,
      name: item.name,
      quantity: 1,
      equipped: false,
      description: String(item.data.description ?? ""),
    });
  }
  const merged = new Map<string, ActorSystem["inventory"][number]>();
  for (const item of inventory) {
    const existing = merged.get(item.id);
    if (existing) existing.quantity += item.quantity;
    else merged.set(item.id, item);
  }
  return {
    inventory: [...merged.values()],
    currencyGp: Math.floor(valueCopper / 100),
    currencyCp: valueCopper % 100,
    manualTypes,
    issues,
    complete: issues.length === 0,
  };
}
