import {
  ABILITY_LABELS,
  DAMAGE_LABELS,
  SKILL_ABILITY,
  SKILL_LABELS,
  type Ability,
  type ActorSystem,
  type SkillKey,
} from "@vtt/core";
import { EditorSection, NumberField, type MutateActorSystem } from "./ActorEditorFields";

export function EssentialsSection({
  system,
  ruleset,
  name,
  setName,
  mutate,
}: {
  system: ActorSystem;
  ruleset: "5e-2014" | "5e-2024";
  name: string;
  setName: (name: string) => void;
  mutate: MutateActorSystem;
}) {
  return (
    <>
      <label>
        Nome
        <input
          required
          maxLength={120}
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </label>
      <EditorSection title="Identidade">
        <div className="editor-grid">
          <label>
            Classe
            <input
              value={system.bio.class}
              onChange={(event) =>
                mutate((next) => {
                  next.bio.class = event.target.value;
                })
              }
            />
          </label>
          <label>
            {ruleset === "5e-2024" ? "Espécie" : "Raça"}
            <input
              value={system.bio.race}
              onChange={(event) =>
                mutate((next) => {
                  next.bio.race = event.target.value;
                })
              }
            />
          </label>
          <NumberField
            label="Nível"
            min={1}
            max={20}
            value={system.bio.level}
            onChange={(value) =>
              mutate((next) => {
                next.bio.level = value;
              })
            }
          />
        </div>
      </EditorSection>
      <EditorSection title="Vitalidade e defesa">
        <div className="editor-grid">
          <NumberField
            label="PV atual"
            value={system.hp.value}
            onChange={(value) =>
              mutate((next) => {
                next.hp.value = value;
              })
            }
          />
          <NumberField
            label="PV máximo"
            min={1}
            value={system.hp.max}
            onChange={(value) =>
              mutate((next) => {
                next.hp.max = value;
              })
            }
          />
          <NumberField
            label="PV temporários"
            value={system.hp.temp}
            onChange={(value) =>
              mutate((next) => {
                next.hp.temp = value;
              })
            }
          />
          <NumberField
            label="Classe de armadura"
            value={system.ac}
            onChange={(value) =>
              mutate((next) => {
                next.ac = value;
              })
            }
          />
          <NumberField
            label="Deslocamento (ft)"
            value={system.speed}
            onChange={(value) =>
              mutate((next) => {
                next.speed = value;
              })
            }
          />
          <NumberField
            label="Bônus de proficiência"
            min={0}
            max={10}
            value={system.proficiencyBonus}
            onChange={(value) =>
              mutate((next) => {
                next.proficiencyBonus = value;
              })
            }
          />
        </div>
      </EditorSection>
      <EditorSection title="Atributos">
        <div className="editor-grid">
          {(Object.keys(ABILITY_LABELS) as Ability[]).map((ability) => (
            <NumberField
              key={ability}
              label={ABILITY_LABELS[ability]}
              min={1}
              max={30}
              value={system.abilities[ability].score}
              onChange={(value) =>
                mutate((next) => {
                  next.abilities[ability].score = value;
                })
              }
            />
          ))}
        </div>
      </EditorSection>
    </>
  );
}

export function ProficienciesSection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <>
      <EditorSection title="Salvaguardas">
        <div className="editor-grid">
          {(Object.keys(ABILITY_LABELS) as Ability[]).map((ability) => (
            <label className="check-label" key={ability}>
              <input
                type="checkbox"
                checked={system.saves[ability]?.proficient ?? false}
                onChange={(event) =>
                  mutate((next) => {
                    next.saves[ability] = { proficient: event.target.checked };
                  })
                }
              />
              {ABILITY_LABELS[ability]}
            </label>
          ))}
        </div>
      </EditorSection>
      <EditorSection title="Perícias">
        <div className="proficiency-editor">
          <p>Especialização dobra o bônus de proficiência.</p>
          {(Object.keys(SKILL_ABILITY) as SkillKey[]).map((skill) => (
            <div key={skill}>
              <span>{SKILL_LABELS[skill]}</span>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={system.skills[skill]?.proficient ?? false}
                  onChange={(event) =>
                    mutate((next) => {
                      next.skills[skill] = {
                        proficient: event.target.checked,
                        expertise: event.target.checked && (next.skills[skill]?.expertise ?? false),
                      };
                    })
                  }
                />
                Proficiente
              </label>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={system.skills[skill]?.expertise ?? false}
                  onChange={(event) =>
                    mutate((next) => {
                      next.skills[skill] = {
                        proficient:
                          event.target.checked || (next.skills[skill]?.proficient ?? false),
                        expertise: event.target.checked,
                      };
                    })
                  }
                />
                Especialista
              </label>
            </div>
          ))}
        </div>
      </EditorSection>
    </>
  );
}

export function StorySection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <>
      <details>
        <summary>Defesas de dano incondicionais</summary>
        <p>
          Marque somente defesas sempre aplicáveis. Exceções de armas mágicas, materiais e outras
          condições ficam na descrição para decisão do mestre.
        </p>
        {(["resist", "immune", "vulnerable"] as const).map((kind) => (
          <fieldset key={kind}>
            <legend>
              {
                { resist: "Resistências", immune: "Imunidades", vulnerable: "Vulnerabilidades" }[
                  kind
                ]
              }
            </legend>
            <div className="editor-grid">
              {Object.entries(DAMAGE_LABELS).map(([type, label]) => (
                <label className="check-label" key={type}>
                  <input
                    type="checkbox"
                    checked={
                      system.damageTraits?.[kind].includes(type as keyof typeof DAMAGE_LABELS) ??
                      false
                    }
                    onChange={(event) =>
                      mutate((next) => {
                        next.damageTraits ??= { resist: [], immune: [], vulnerable: [] };
                        const damageType = type as keyof typeof DAMAGE_LABELS;
                        next.damageTraits[kind] = event.target.checked
                          ? [...next.damageTraits[kind], damageType]
                          : next.damageTraits[kind].filter((value) => value !== damageType);
                      })
                    }
                  />
                  {label}
                </label>
              ))}
            </div>
          </fieldset>
        ))}
      </details>
      <div className="editor-grid">
        <label>
          Antecedente
          <input
            value={system.bio.background}
            onChange={(event) =>
              mutate((next) => {
                next.bio.background = event.target.value;
              })
            }
          />
        </label>
        <label>
          Alinhamento
          <input
            value={system.bio.alignment}
            onChange={(event) =>
              mutate((next) => {
                next.bio.alignment = event.target.value;
              })
            }
          />
        </label>
        <NumberField
          label="Visão no escuro (ft)"
          value={system.senses.darkvision}
          onChange={(value) =>
            mutate((next) => {
              next.senses.darkvision = value;
            })
          }
        />
      </div>
      <label>
        Idiomas, separados por vírgula
        <input
          value={system.languages.join(",")}
          onChange={(event) =>
            mutate((next) => {
              next.languages = event.target.value.split(",");
            })
          }
        />
      </label>
      <label>
        Notas
        <textarea
          rows={5}
          value={system.bio.notes}
          onChange={(event) =>
            mutate((next) => {
              next.bio.notes = event.target.value;
            })
          }
        />
      </label>
    </>
  );
}
