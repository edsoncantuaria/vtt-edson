import { useState } from "react";
import {
  ABILITY_LABELS,
  SKILL_LABELS,
  SKILL_ABILITY,
  abilityModifier,
  formatModifier,
  ActorSystemSchema,
  type Ability,
  type Actor,
  type ActorSystem,
  type SkillKey,
} from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";
export function ClassicSheet({ actor, canEdit }: { actor: Actor; canEdit: boolean }) {
  const { upsertActor, ruleset } = useSession();
  const [draft, setDraft] = useState(() => structuredClone(actor.system));
  const [baseline, setBaseline] = useState(() => JSON.stringify(actor.system));
  const [name, setName] = useState(actor.name);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const s = dirty ? draft : actor.system;
  function change(fn: (s: ActorSystem) => void) {
    const next = structuredClone(s);
    fn(next);
    setDraft(next);
    if (!dirty) {
      setBaseline(JSON.stringify(actor.system));
      setEditRevision(actor.revision ?? 0);
    }
    setDirty(true);
  }
  function num(
    label: string,
    value: number,
    update: (s: ActorSystem, n: number) => void,
    min = 0,
    max = 999,
  ) {
    return (
      <label>
        {label}
        <input
          type="number"
          required
          min={min}
          max={max}
          value={value}
          onChange={(e) => change((s) => update(s, Number(e.target.value)))}
        />
      </label>
    );
  }
  const [editRevision, setEditRevision] = useState(actor.revision ?? 0);
  async function save() {
    setError("");
    if (JSON.stringify(actor.system) !== baseline) {
      setError("A ficha mudou em outra aba. Recarregue os dados antes de salvar.");
      return;
    }
    const parsed = ActorSystemSchema.safeParse(s);
    if (!parsed.success || s.hp.value > s.hp.max || s.hitDice.used > s.hitDice.total) {
      setError("Confira os valores: PV e dados de vida usados não podem ultrapassar o total.");
      return;
    }
    setBusy(true);
    try {
      const result = await api<{ actor: Actor }>(`/actors/${actor.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: name.trim(), system: parsed.data, revision: editRevision }),
      });
      upsertActor(result.actor);
      setDirty(false);
      setEditRevision(result.actor.revision ?? 0);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível salvar.");
    } finally {
      setBusy(false);
    }
  }
  const spellMod = abilityModifier(s.abilities[s.spellcastingAbility].score);
  return (
    <form
      className="classic-sheet"
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <fieldset disabled={!canEdit || busy}>
        <legend>Ficha de personagem · {ruleset.slice(-4)}</legend>
        <div className="classic-identity">
          <label>
            Nome
            <input
              required
              maxLength={120}
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                change(() => {});
              }}
            />
          </label>
          <label>
            Classe
            <input
              value={s.bio.class}
              onChange={(e) =>
                change((s) => {
                  s.bio.class = e.target.value;
                })
              }
            />
          </label>
          {num(
            "Nível",
            s.bio.level,
            (s, n) => {
              s.bio.level = n;
            },
            1,
            20,
          )}
          <label>
            {ruleset === "5e-2024" ? "Espécie" : "Raça"}
            <input
              value={s.bio.race}
              onChange={(e) =>
                change((s) => {
                  s.bio.race = e.target.value;
                })
              }
            />
          </label>
          <label>
            Antecedente
            <input
              value={s.bio.background}
              onChange={(e) =>
                change((s) => {
                  s.bio.background = e.target.value;
                })
              }
            />
          </label>
          <label>
            Alinhamento
            <input
              value={s.bio.alignment}
              onChange={(e) =>
                change((s) => {
                  s.bio.alignment = e.target.value;
                })
              }
            />
          </label>
        </div>
        <div className="classic-columns">
          <section>
            <h4>Atributos e testes</h4>
            <div className="classic-abilities">
              {(Object.keys(ABILITY_LABELS) as Ability[]).map((k) => (
                <div key={k}>
                  {num(
                    ABILITY_LABELS[k],
                    s.abilities[k].score,
                    (s, n) => {
                      s.abilities[k].score = n;
                    },
                    1,
                    30,
                  )}
                  <output>{formatModifier(abilityModifier(s.abilities[k].score))}</output>
                  <label className="check-label">
                    <input
                      type="checkbox"
                      checked={s.saves[k]?.proficient ?? false}
                      onChange={(e) =>
                        change((s) => {
                          s.saves[k] = { proficient: e.target.checked };
                        })
                      }
                    />
                    Salvaguarda{" "}
                    {formatModifier(
                      abilityModifier(s.abilities[k].score) +
                        (s.saves[k]?.proficient ? s.proficiencyBonus : 0),
                    )}
                  </label>
                </div>
              ))}
            </div>
            <h4>Perícias</h4>
            {(Object.keys(SKILL_ABILITY) as SkillKey[]).map((k) => (
              <div className="classic-skill" key={k}>
                <label className="check-label">
                  <input
                    type="checkbox"
                    checked={s.skills[k]?.proficient ?? false}
                    onChange={(e) =>
                      change((s) => {
                        s.skills[k] = {
                          proficient: e.target.checked,
                          expertise: e.target.checked && (s.skills[k]?.expertise ?? false),
                        };
                      })
                    }
                  />
                  {SKILL_LABELS[k]}
                </label>
                <output>
                  {formatModifier(
                    abilityModifier(s.abilities[SKILL_ABILITY[k]].score) +
                      (s.skills[k]?.expertise ? 2 : s.skills[k]?.proficient ? 1 : 0) *
                        s.proficiencyBonus,
                  )}
                </output>
              </div>
            ))}
          </section>
          <section>
            <h4>Combate e vitalidade</h4>
            <div className="classic-vitals">
              {num("Classe de armadura", s.ac, (s, n) => {
                s.ac = n;
              })}
              {num("Deslocamento (ft)", s.speed, (s, n) => {
                s.speed = n;
              })}
              {num(
                "Proficiência",
                s.proficiencyBonus,
                (s, n) => {
                  s.proficiencyBonus = n;
                },
                0,
                10,
              )}
              {num("PV atuais", s.hp.value, (s, n) => {
                s.hp.value = n;
              })}
              {num(
                "PV máximos",
                s.hp.max,
                (s, n) => {
                  s.hp.max = n;
                },
                1,
              )}
              {num("PV temporários", s.hp.temp, (s, n) => {
                s.hp.temp = n;
              })}
            </div>
            <p>
              Iniciativa: {formatModifier(abilityModifier(s.abilities.dex.score))} · Percepção
              passiva:{" "}
              {10 +
                abilityModifier(s.abilities.wis.score) +
                (s.skills.perception?.expertise
                  ? 2 * s.proficiencyBonus
                  : s.skills.perception?.proficient
                    ? s.proficiencyBonus
                    : 0)}
            </p>
            <label className="check-label">
              <input
                type="checkbox"
                checked={s.inspiration}
                onChange={(e) =>
                  change((s) => {
                    s.inspiration = e.target.checked;
                  })
                }
              />
              {ruleset === "5e-2024" ? "Inspiração heroica" : "Inspiração"}
            </label>
            <h4>Condições</h4>
            <label>
              Condições ativas (separadas por vírgula)
              <input
                value={s.conditions.join(",")}
                placeholder="Envenenado, caído…"
                onChange={(e) =>
                  change((s) => {
                    s.conditions = e.target.value.split(",").filter(Boolean);
                  })
                }
              />
            </label>
            <p>
              Consulte os efeitos no compêndio de regras. As condições são registradas na ficha;
              ajustes de dados podem ser definidos pelo mestre nas regras da casa.
            </p>
            <h4>Dados de vida</h4>
            <div className="classic-vitals">
              {num(
                "Dado (faces)",
                s.hitDice.die,
                (s, n) => {
                  s.hitDice.die = n;
                },
                4,
                12,
              )}
              {num(
                "Total",
                s.hitDice.total,
                (s, n) => {
                  s.hitDice.total = n;
                },
                0,
                20,
              )}
              {num(
                "Usados",
                s.hitDice.used,
                (s, n) => {
                  s.hitDice.used = n;
                },
                0,
                20,
              )}
            </div>
            <h4>Salvaguardas contra morte</h4>
            <div className="classic-vitals">
              {num(
                "Sucessos",
                s.deathSaves.success,
                (s, n) => {
                  s.deathSaves.success = n;
                },
                0,
                3,
              )}
              {num(
                "Falhas",
                s.deathSaves.failure,
                (s, n) => {
                  s.deathSaves.failure = n;
                },
                0,
                3,
              )}
            </div>
            <h4>Conjuração</h4>
            <label>
              Atributo de conjuração
              <select
                value={s.spellcastingAbility}
                onChange={(e) =>
                  change((s) => {
                    s.spellcastingAbility = e.target.value as Ability;
                  })
                }
              >
                {(Object.keys(ABILITY_LABELS) as Ability[]).map((k) => (
                  <option key={k} value={k}>
                    {ABILITY_LABELS[k]}
                  </option>
                ))}
              </select>
            </label>
            <p>
              CD {8 + s.proficiencyBonus + spellMod} · Ataque mágico{" "}
              {formatModifier(s.proficiencyBonus + spellMod)}
            </p>
            <div className="classic-vitals">
              {Array.from({ length: 9 }, (_, i) => String(i + 1)).map((level) => (
                <div key={level}>
                  {num(
                    level + "º · espaços",
                    s.spells.slots[level]?.max ?? 0,
                    (s, n) => {
                      s.spells.slots[level] = {
                        max: n,
                        used: Math.min(n, s.spells.slots[level]?.used ?? 0),
                      };
                    },
                    0,
                    20,
                  )}
                  {num(
                    "Usados",
                    s.spells.slots[level]?.used ?? 0,
                    (s, n) => {
                      s.spells.slots[level] = { max: s.spells.slots[level]?.max ?? 0, used: n };
                    },
                    0,
                    s.spells.slots[level]?.max ?? 0,
                  )}
                </div>
              ))}
            </div>
          </section>
        </div>
        <h4>Equipamento e magias</h4>
        <p>
          Use “Editar ficha” para incluir ou remover itens, magias e ações, ou importe pelo
          compêndio.
        </p>
        <div className="classic-columns">
          <section>
            {s.inventory.map((item, i) => (
              <label key={item.id}>
                {item.name}
                <input
                  aria-label={"Quantidade de " + item.name}
                  type="number"
                  min={0}
                  value={item.quantity}
                  onChange={(e) =>
                    change((s) => {
                      s.inventory[i].quantity = Number(e.target.value);
                    })
                  }
                />
              </label>
            ))}
          </section>
          <section>
            {s.spells.known.map((spell, i) => (
              <label className="check-label" key={spell.id}>
                <input
                  type="checkbox"
                  checked={spell.prepared}
                  onChange={(e) =>
                    change((s) => {
                      s.spells.known[i].prepared = e.target.checked;
                    })
                  }
                />
                {spell.name} · {spell.level === 0 ? "Truque" : spell.level + "º"}
              </label>
            ))}
          </section>
        </div>
        <h4>Características, proficiências e história</h4>
        <label>
          Outras proficiências
          <input
            value={s.proficiencies}
            onChange={(e) =>
              change((s) => {
                s.proficiencies = e.target.value;
              })
            }
          />
        </label>
        <label>
          Idiomas
          <input
            value={s.languages.join(", ")}
            onChange={(e) =>
              change((s) => {
                s.languages = e.target.value.split(",").map((v) => v.trim());
              })
            }
          />
        </label>
        {s.features.map((f) => (
          <details key={f.id}>
            <summary>{f.name}</summary>
            <p className="catalog-prose">{f.description}</p>
          </details>
        ))}
        <label>
          Notas
          <textarea
            rows={5}
            value={s.bio.notes}
            onChange={(e) =>
              change((s) => {
                s.bio.notes = e.target.value;
              })
            }
          />
        </label>
      </fieldset>
      {error && (
        <p role="alert" className="notice notice--error">
          {error}
        </p>
      )}
      {canEdit && (
        <footer className="classic-save">
          <span>{dirty ? "Alterações não salvas" : "Ficha atualizada"}</span>
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              setDirty(false);
              setDraft(structuredClone(actor.system));
              setBaseline(JSON.stringify(actor.system));
              setEditRevision(actor.revision ?? 0);
              setName(actor.name);
              setError("");
            }}
          >
            Recarregar
          </button>
          <button className="primary" disabled={!dirty || busy}>
            {busy ? "Salvando…" : "Salvar ficha"}
          </button>
        </footer>
      )}
    </form>
  );
}
