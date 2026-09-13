import type { Actor, Ability } from "@vtt/core";
import { ABILITY_LABELS, abilityModifier, formatModifier } from "@vtt/core";
import { fiveToolsText } from "../lib/fiveToolsText";
export function MonsterStatBlock({ actor }: { actor: Actor }) {
  const s = actor.system;
  const raw = s.statBlock ?? {};
  const sections = [
    ["trait", "Características"],
    ["action", "Ações"],
    ["bonus", "Ações bônus"],
    ["reaction", "Reações"],
    ["legendary", "Ações lendárias"],
    ["mythic", "Ações míticas"],
    ["spellcasting", "Conjuração"],
  ];
  return (
    <article className="monster-stat-block">
      <h3>{actor.name}</h3>
      <p>
        {s.bio.race} {s.bio.alignment}
      </p>
      <dl>
        <dt>Classe de armadura</dt>
        <dd>{s.ac}</dd>
        <dt>Pontos de vida</dt>
        <dd>
          {s.hp.value} / {s.hp.max}
        </dd>
        <dt>Deslocamento</dt>
        <dd>{s.speed} ft</dd>
      </dl>
      <div className="ability-grid">
        {(Object.keys(ABILITY_LABELS) as Ability[]).map((k) => (
          <div key={k}>
            <b>{ABILITY_LABELS[k]}</b>
            <p>
              {s.abilities[k].score} ({formatModifier(abilityModifier(s.abilities[k].score))})
            </p>
          </div>
        ))}
      </div>
      {[
        ["save", "Salvaguardas"],
        ["skill", "Perícias"],
        ["senses", "Sentidos"],
        ["languages", "Idiomas"],
        ["vulnerable", "Vulnerabilidades"],
        ["resist", "Resistências"],
        ["immune", "Imunidades"],
        ["conditionImmune", "Imunidades a condições"],
        ["cr", "Desafio"],
      ].map(
        ([key, label]) =>
          raw[key] != null && (
            <p key={key}>
              <b>{label}: </b>
              {fiveToolsText(raw[key])}
            </p>
          ),
      )}
      {sections.map(
        ([key, label]) =>
          Array.isArray(raw[key]) && (
            <section key={key}>
              <h4>{label}</h4>
              {(raw[key] as Record<string, unknown>[]).map((entry, i) => (
                <div key={i}>
                  <b>{String(entry.name ?? "")}</b>
                  <p className="catalog-prose">
                    {fiveToolsText(entry.entries ?? entry.headerEntries ?? entry.desc)}
                  </p>
                  {key === "spellcasting" && (
                    <pre style={{ whiteSpace: "pre-wrap" }}>
                      {fiveToolsText({
                        ...(entry.spells ? { círculos: entry.spells } : {}),
                        ...(entry.daily ? { diárias: entry.daily } : {}),
                        ...(entry.will ? { "à vontade": entry.will } : {}),
                        ...(entry.rest ? { descanso: entry.rest } : {}),
                      })}
                    </pre>
                  )}
                </div>
              ))}
            </section>
          ),
      )}
      {!s.statBlock &&
        s.features.map((f) => (
          <section key={f.id}>
            <h4>{f.name}</h4>
            <p>{f.description}</p>
          </section>
        ))}
    </article>
  );
}
