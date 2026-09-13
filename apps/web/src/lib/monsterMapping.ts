import { fiveToolsText } from "./fiveToolsText";
import { emptyActorSystem, DamageTypeSchema, type ActorSystem } from "@vtt/core";

/**
 * Converte o blob solto de um verbete de monstro do compêndio (curado à mão ou
 * importado do Open5e — os dois têm nomes de campo diferentes) numa ActorSystem
 * jogável. Best-effort: quando não reconhece um campo, cai no padrão e guarda o
 * bloco de stats original em `bio.notes` pra não perder informação.
 */
export function monsterDataToActorSystem(data: Record<string, unknown>): ActorSystem {
  const system = emptyActorSystem();
  const raw =
    data.raw && typeof data.raw === "object" ? (data.raw as Record<string, unknown>) : data;
  system.statBlock = structuredClone(raw);
  system.damageTraits = { resist: [], immune: [], vulnerable: [] };
  for (const kind of ["resist", "immune", "vulnerable"] as const) {
    const entries = raw[kind];
    if (Array.isArray(entries))
      system.damageTraits[kind] = entries.flatMap((entry) => {
        const parsed = DamageTypeSchema.safeParse(entry);
        return parsed.success ? [parsed.data] : [];
      });
  }
  if (raw.save && typeof raw.save === "object")
    for (const [key, value] of Object.entries(raw.save)) {
      if (system.saves[key] && typeof value === "string" && /^[+-]?\d+$/.test(value))
        system.saves[key].bonus = Number(value);
    }
  for (const section of ["trait", "action", "bonus", "reaction", "legendary", "mythic"]) {
    const entries = raw[section];
    if (!Array.isArray(entries)) continue;
    for (const entry of entries) {
      if (!entry || typeof entry.name !== "string") continue;
      const serialized = JSON.stringify(entry.entries ?? []);
      const hit = serialized.match(/\{@hit ([+-]?\d+)\}/)?.[1];
      const damageMatches = [...serialized.matchAll(/\{@damage ([^}|]+)/g)];
      const damage =
        damageMatches.length === 1 ? damageMatches[0][1].replaceAll(" ", "") : undefined;
      const damageType = DamageTypeSchema.safeParse(
        serialized.match(/\{@damage [^}]+\}\s+(\w+) damage/)?.[1],
      );
      if (hit || damage)
        system.actions.push({
          id: crypto.randomUUID(),
          name: entry.name,
          kind: "attack",
          attackFormula: hit ? "1d20" + (Number(hit) >= 0 ? "+" : "") + hit : undefined,
          damageFormula: damage,
          damageType: damageType.success ? damageType.data : undefined,
          economy:
            section === "bonus"
              ? "bonus"
              : section === "reaction"
                ? "reaction"
                : section === "action"
                  ? "action"
                  : "other",
          description: fiveToolsText(entry.entries),
        });
    }
  }

  const abilitiesRaw = (data.abilities as Record<string, number> | undefined) ?? {
    str: data.strength as number | undefined,
    dex: data.dexterity as number | undefined,
    con: data.constitution as number | undefined,
    int: data.intelligence as number | undefined,
    wis: data.wisdom as number | undefined,
    cha: data.charisma as number | undefined,
  };
  for (const key of ["str", "dex", "con", "int", "wis", "cha"] as const) {
    const score = raw[key] ?? abilitiesRaw?.[key];
    if (typeof score === "number") system.abilities[key].score = score;
  }

  const hpFromParens = typeof data.hp === "string" ? data.hp.match(/\((\d+)\)/)?.[1] : undefined;
  const hpMax =
    (data.hit_points as number | undefined) ??
    (hpFromParens ? Number(hpFromParens) : undefined) ??
    10;
  system.hp = { value: hpMax, max: hpMax, temp: 0 };

  system.ac = (data.armor_class as number | undefined) ?? (data.ac as number | undefined) ?? 10;

  const speedObj = data.speed as { walk?: number } | undefined;
  const speedFromString = typeof data.speed === "string" ? data.speed.match(/\d+/)?.[0] : undefined;
  system.speed = speedObj?.walk ?? (speedFromString ? Number(speedFromString) : undefined) ?? 30;

  const actions = (data.actions as Array<{ name?: string; desc?: string }> | undefined) ?? [];
  system.features = actions
    .filter((a) => a?.name)
    .map((a) => ({
      id: crypto.randomUUID(),
      name: a.name as string,
      description: a.desc,
    }));

  const summary = [data.size, data.type, data.alignment].filter(Boolean).join(" · ");
  system.bio = {
    class: "",
    level: 1,
    race: (data.type as string) ?? "",
    background: "",
    alignment: (data.alignment as string) ?? "",
    notes: [summary, JSON.stringify(data, null, 2)].filter(Boolean).join("\n\n"),
  };

  return system;
}
