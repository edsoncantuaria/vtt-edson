import {
  ABILITY_LABELS,
  DAMAGE_LABELS,
  type ActorAction,
  type ActorDocument,
  type ActorSystem,
} from "@vtt/core";
import { Icon } from "../Icon";
import {
  createCustomAction,
  duplicateActorAction,
  type CustomActionPreset,
} from "../../lib/customActions";
import type { MutateActorSystem } from "./ActorEditorFields";
import { useSession } from "../../store/session";

const RESOURCE_KINDS = [
  ["homebrew", "Homebrew / outro"],
  ["rage", "Fúria"],
  ["ki", "Ki (2014)"],
  ["focus", "Foco (2024)"],
  ["channel-divinity", "Canalizar Divindade"],
] as const;
type Recovery = "none" | "one" | "full";
function recoveryFor(
  kind: string | undefined,
  edition: string,
  reset: string,
): { short: Recovery; long: Recovery } {
  if (kind === "rage") return { short: edition === "5e-2024" ? "one" : "none", long: "full" };
  if (kind === "focus" || kind === "ki") return { short: "full", long: "full" };
  if (kind === "channel-divinity")
    return { short: edition === "5e-2024" ? "one" : "full", long: "full" };
  return { short: reset === "short" ? "full" : "none", long: reset === "manual" ? "none" : "full" };
}

export function ActionsSection({
  system,
  documents,
  mutate,
  canManage,
}: {
  system: ActorSystem;
  documents: ActorDocument[];
  mutate: MutateActorSystem;
  canManage: boolean;
}) {
  const ruleset = useSession((state) => state.ruleset) ?? "5e-2014";
  return (
    <>
      <p className="panel-hint">
        Crie um ataque ou conjuração. A fórmula aceita dados e modificadores, por exemplo{" "}
        <code>1d20+7</code> ou <code>8d6</code>. O efeito usa apenas URL HTTPS.
      </p>
      <details>
        <summary>Recursos consumíveis</summary>
        <p className="panel-hint">
          Use para Ki, Canalizar Divindade, Superioridade, cargas e outros usos. O consumo feito por
          uma ação participa do histórico e pode ser desfeito.
        </p>
        {system.resources.map((resource, index) => (
          <div className="editor-grid" key={resource.id}>
            <label>
              Tipo
              <select
                value={resource.kind ?? "homebrew"}
                onChange={(event) =>
                  mutate((next) => {
                    const item = next.resources[index];
                    item.kind = event.target.value as typeof item.kind;
                    item.recovery = recoveryFor(item.kind, ruleset, item.reset);
                    item.edition = ruleset;
                  })
                }
              >
                {RESOURCE_KINDS.map(([id, label]) => (
                  <option key={id} value={id}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Nome
              <input
                value={resource.name}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].name = event.target.value;
                  })
                }
              />
            </label>
            <label>
              Origem / classe / documento
              <input
                maxLength={160}
                value={resource.source ?? ""}
                placeholder="Classe · SRD, item ou criação própria"
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].source = event.target.value;
                  })
                }
              />
            </label>
            <label>
              Edição do recurso
              <select
                value={resource.edition ?? ruleset}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].edition = event.target.value as "5e-2014" | "5e-2024";
                  })
                }
              >
                <option value="5e-2014">5e 2014</option>
                <option value="5e-2024">5e 2024</option>
              </select>
            </label>
            <label>
              Custo padrão de cada ação
              <input
                type="number"
                min={1}
                max={1000}
                value={resource.defaultCost ?? 1}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].defaultCost = Math.max(
                      1,
                      Math.min(1000, Number(event.target.value)),
                    );
                  })
                }
              />
            </label>
            <label>
              Máximo
              <input
                type="number"
                min={0}
                max={100000}
                value={resource.max}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].max = Math.max(0, Number(event.target.value));
                    next.resources[index].used = Math.min(
                      next.resources[index].used,
                      next.resources[index].max,
                    );
                  })
                }
              />
            </label>
            <label>
              Usados
              <input
                type="number"
                min={0}
                max={resource.max}
                value={resource.used}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].used = Math.max(
                      0,
                      Math.min(next.resources[index].max, Number(event.target.value)),
                    );
                  })
                }
              />
            </label>
            {(["short", "long"] as const).map((rest) => (
              <label key={rest}>
                Recuperação no descanso {rest === "short" ? "curto" : "longo"}
                <select
                  value={
                    resource.recovery?.[rest] ??
                    recoveryFor(resource.kind, resource.edition ?? ruleset, resource.reset)[rest]
                  }
                  onChange={(event) =>
                    mutate((next) => {
                      const item = next.resources[index];
                      item.recovery = {
                        ...recoveryFor(item.kind, item.edition ?? ruleset, item.reset),
                        ...item.recovery,
                        [rest]: event.target.value as Recovery,
                      };
                    })
                  }
                >
                  <option value="none">Não recupera</option>
                  <option value="one">Recupera 1 uso</option>
                  <option value="full">Recupera todos</option>
                </select>
              </label>
            ))}
            {canManage && (
              <label>
                <input
                  type="checkbox"
                  checked={!!resource.scaling}
                  onChange={(event) =>
                    mutate((next) => {
                      next.resources[index].scaling = event.target.checked
                        ? { className: "", byLevel: {} }
                        : undefined;
                    })
                  }
                />{" "}
                Evoluir máximo pela tabela da classe
              </label>
            )}
            {canManage && resource.scaling && (
              <>
                <label>
                  Classe exata da progressão
                  {(system.progression?.classes.length ?? 0) > 0 ? (
                    <select
                      value={resource.scaling.classId ?? ""}
                      onChange={(event) =>
                        mutate((next) => {
                          const cls = next.progression?.classes.find(
                            (row) => row.classId === Number(event.target.value),
                          );
                          if (!cls) return;
                          next.resources[index].scaling = {
                            ...next.resources[index].scaling!,
                            classId: cls.classId,
                            className: cls.name,
                            classSource: cls.source,
                          };
                        })
                      }
                    >
                      <option value="">Selecionar classe da ficha</option>
                      {system
                        .progression!.classes.filter((row) => row.classId)
                        .map((row) => (
                          <option key={row.classId} value={row.classId}>
                            {row.name} · {row.source ?? "fonte da ficha"}
                          </option>
                        ))}
                    </select>
                  ) : (
                    <input
                      value={resource.scaling.className}
                      maxLength={120}
                      placeholder="Classe da ficha"
                      onChange={(event) =>
                        mutate((next) => {
                          next.resources[index].scaling!.className = event.target.value;
                        })
                      }
                    />
                  )}
                </label>
                {Array.from({ length: 20 }, (_, i) => i + 1).map((level) => (
                  <label key={level}>
                    Máximo no nível {level}
                    <input
                      type="number"
                      min={0}
                      max={100000}
                      placeholder="sem mudança"
                      value={resource.scaling?.byLevel[String(level)] ?? ""}
                      onChange={(event) =>
                        mutate((next) => {
                          const table = next.resources[index].scaling!.byLevel;
                          if (event.target.value === "") delete table[String(level)];
                          else
                            table[String(level)] = Math.max(
                              0,
                              Math.min(100000, Number(event.target.value)),
                            );
                        })
                      }
                    />
                  </label>
                ))}
              </>
            )}
            <button
              type="button"
              className="danger"
              onClick={() =>
                mutate((next) => {
                  const resourceId = next.resources[index].id;
                  next.resources.splice(index, 1);
                  next.actions.forEach((action) => {
                    if (action.resourceId === resourceId) {
                      action.resourceId = undefined;
                      action.resourceCost = undefined;
                    }
                  });
                })
              }
            >
              Remover recurso
            </button>
          </div>
        ))}
        <button
          type="button"
          onClick={() =>
            mutate((next) => {
              next.resources.push({
                id: crypto.randomUUID(),
                name: "Novo recurso",
                max: 1,
                used: 0,
                reset: "manual",
                kind: "homebrew",
                source: "Ficha · homebrew",
                edition: ruleset,
                defaultCost: 1,
                recovery: { short: "none", long: "none" },
              });
            })
          }
        >
          <Icon name="plus" size={16} />
          Adicionar recurso
        </button>
      </details>
      {system.actions.some((action) => action.id.startsWith("document:")) && (
        <details>
          <summary>Ações automáticas dos documentos</summary>
          <p className="panel-hint">
            Estas ações são derivadas do 5e.tools/documento canônico. Edite ou remova o documento de
            origem para alterá-las.
          </p>
          {system.actions
            .filter((action) => action.id.startsWith("document:"))
            .map((action) => (
              <div key={action.id}>
                <p>
                  <b>{action.name}</b> ·{" "}
                  {action.attackFormula ?? action.damageFormula ?? "efeito/salvaguarda"}
                </p>
                <button
                  type="button"
                  onClick={() =>
                    mutate((next) =>
                      next.actions.push(duplicateActorAction(action, crypto.randomUUID())),
                    )
                  }
                >
                  Duplicar como ação independente
                </button>
              </div>
            ))}
        </details>
      )}
      {system.actions.map((action, index) =>
        action.id.startsWith("document:") ? null : (
          <ActionEditor
            key={action.id}
            action={action}
            index={index}
            system={system}
            documents={documents}
            mutate={mutate}
            canManage={canManage}
          />
        ),
      )}
      <label>
        Criar ação sem código
        <select
          defaultValue=""
          onChange={(event) => {
            if (!event.target.value) return;
            const preset = event.target.value as CustomActionPreset;
            mutate((next) => {
              next.actions.push(createCustomAction(preset, crypto.randomUUID()));
            });
            event.target.value = "";
          }}
        >
          <option value="">Escolher modelo…</option>
          <option value="attack">Ataque simples</option>
          <option value="torch">Ataque com tocha</option>
          <option value="maneuver">Manobra</option>
          <option value="potion">Poção de cura</option>
          <option value="feature">Habilidade personalizada</option>
        </select>
      </label>
    </>
  );
}

function ActionEditor({
  action,
  index,
  system,
  documents,
  mutate,
  canManage,
}: {
  action: ActorAction;
  index: number;
  system: ActorSystem;
  documents: ActorDocument[];
  mutate: MutateActorSystem;
  canManage: boolean;
}) {
  return (
    <section className="editor-item">
      <div className="editor-item__head">
        <label>
          Ação
          <input
            required
            value={action.name}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].name = event.target.value;
              })
            }
          />
        </label>
        <label>
          Tipo
          <select
            value={action.kind}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].kind = event.target.value as ActorAction["kind"];
              })
            }
          >
            <option value="attack">Ataque</option>
            <option value="spell">Magia</option>
            <option value="feature">Habilidade</option>
            <option value="item">Item</option>
          </select>
        </label>
        <button
          type="button"
          aria-label={`Duplicar ${action.name}`}
          onClick={() =>
            mutate((next) => next.actions.push(duplicateActorAction(action, crypto.randomUUID())))
          }
        >
          Duplicar
        </button>
        <button
          type="button"
          className="icon-button danger"
          aria-label={`Remover ${action.name}`}
          onClick={() =>
            mutate((next) => {
              next.actions.splice(index, 1);
            })
          }
        >
          <Icon name="trash" size={16} />
        </button>
      </div>
      <div className="editor-grid">
        <label>
          Origem da ação
          <input
            maxLength={160}
            value={action.origin ?? ""}
            placeholder="Mesa · regra da casa / fonte"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].origin = event.target.value || undefined;
              })
            }
          />
        </label>
        <label>
          Visibilidade
          <select
            value={action.visibility ?? "public"}
            disabled={!canManage && action.visibility === "gm"}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].visibility = event.target.value as "public" | "gm";
              })
            }
          >
            <option value="public">Pública para participantes autorizados</option>
            {canManage && <option value="gm">Somente mestre</option>}
          </select>
        </label>
      </div>
      <div className="editor-grid">
        <label>
          Fórmula de ataque
          <input
            value={action.attackFormula ?? ""}
            placeholder="1d20+7"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].attackFormula = event.target.value || undefined;
              })
            }
          />
        </label>
        <label>
          Fórmula de dano {action.damageParts?.length ? "(use componentes abaixo)" : ""}
          <input
            value={action.damageFormula ?? ""}
            disabled={!!action.damageParts?.length}
            placeholder="8d6"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].damageFormula = event.target.value || undefined;
              })
            }
          />
        </label>
      </div>
      <details>
        <summary>Componentes de dano por tipo (ex.: fogo + cortante)</summary>
        <p className="panel-hint">
          Cada componente tem fórmula e tipo próprios para calcular resistência, imunidade e
          vulnerabilidade separadamente. Até 8 componentes; substituem a fórmula única.
          {action.damageFormula &&
            !action.damageType &&
            " Defina primeiro o tipo de dano da fórmula atual para convertê-la sem adivinhar a regra."}
        </p>
        {action.damageParts?.map((part, partIndex) => (
          <div className="editor-grid" key={partIndex}>
            <label>
              Componente {partIndex + 1} · fórmula
              <input
                value={part.formula}
                placeholder="2d6+1"
                onChange={(event) =>
                  mutate((next) => {
                    next.actions[index].damageParts![partIndex].formula = event.target.value;
                  })
                }
              />
            </label>
            <label>
              Tipo do componente
              <select
                value={part.damageType}
                onChange={(event) =>
                  mutate((next) => {
                    next.actions[index].damageParts![partIndex].damageType = event.target
                      .value as NonNullable<ActorAction["damageParts"]>[number]["damageType"];
                  })
                }
              >
                {Object.entries(DAMAGE_LABELS).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <button
              type="button"
              onClick={() =>
                mutate((next) => {
                  next.actions[index].damageParts = next.actions[index].damageParts!.filter(
                    (_, i) => i !== partIndex,
                  );
                  if (!next.actions[index].damageParts!.length)
                    next.actions[index].damageParts = undefined;
                })
              }
            >
              Remover componente {partIndex + 1}
            </button>
          </div>
        ))}
        <button
          type="button"
          disabled={
            (action.damageParts?.length ?? 0) >= 8 || (!!action.damageFormula && !action.damageType)
          }
          onClick={() =>
            mutate((next) => {
              const target = next.actions[index];
              const seed = target.damageFormula
                ? ({
                    formula: target.damageFormula,
                    damageType: target.damageType!,
                  } as NonNullable<ActorAction["damageParts"]>[number])
                : null;
              target.damageParts = [
                ...(target.damageParts ?? (seed ? [seed] : [])),
                { formula: "1d6", damageType: "fire" },
              ];
              target.damageFormula = undefined;
            })
          }
        >
          Adicionar componente tipado
        </button>
      </details>
      <div className="editor-grid">
        <label>
          Fórmula de cura
          <input
            value={action.healingFormula ?? ""}
            placeholder="1d8+3"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].healingFormula = event.target.value || undefined;
              })
            }
          />
        </label>
        <label>
          Imagem HTTPS (opcional)
          <input
            type="url"
            value={action.imageUrl ?? ""}
            placeholder="https://…"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].imageUrl = event.target.value || undefined;
              })
            }
          />
        </label>
      </div>
      <div className="editor-grid">
        <label>
          Alvos
          <select
            value={action.target ?? ""}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].target = event.target.value
                  ? (event.target.value as ActorAction["target"])
                  : undefined;
              })
            }
          >
            <option value="">Legado · seleção livre</option>
            <option value="self">A própria ficha</option>
            <option value="single">Um alvo</option>
            <option value="multiple">Vários alvos</option>
          </select>
        </label>
        {action.target === "multiple" && (
          <label>
            Máximo de alvos
            <input
              type="number"
              min={1}
              max={50}
              value={action.maxTargets ?? 2}
              onChange={(event) =>
                mutate((next) => {
                  next.actions[index].maxTargets = Math.min(
                    50,
                    Math.max(1, Number(event.target.value)),
                  );
                })
              }
            />
          </label>
        )}
        {action.target !== "self" && (
          <label>
            Alcance em pés (vazio = decisão da mesa)
            <input
              type="number"
              min={0}
              max={10000}
              value={action.rangeFeet ?? ""}
              onChange={(event) =>
                mutate((next) => {
                  next.actions[index].rangeFeet =
                    event.target.value === "" ? undefined : Math.max(0, Number(event.target.value));
                })
              }
            />
          </label>
        )}
      </div>
      <div className="editor-grid">
        <label>
          Salvaguarda
          <select
            value={action.saveAbility ?? ""}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].saveAbility = event.target.value
                  ? (event.target.value as ActorAction["saveAbility"])
                  : undefined;
                next.actions[index].saveEffect ??= "none";
              })
            }
          >
            <option value="">Não exige</option>
            {Object.entries(ABILITY_LABELS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </label>
        {action.saveAbility && (
          <>
            <label>
              CD (vazia = 8 + atributo de magia + proficiência)
              <input
                type="number"
                min={1}
                max={99}
                value={action.saveDc ?? ""}
                onChange={(event) =>
                  mutate((next) => {
                    next.actions[index].saveDc = event.target.value
                      ? Number(event.target.value)
                      : undefined;
                  })
                }
              />
            </label>
            <label>
              No sucesso
              <select
                value={action.saveEffect ?? "none"}
                onChange={(event) =>
                  mutate((next) => {
                    next.actions[index].saveEffect = event.target.value as "half" | "none";
                  })
                }
              >
                <option value="none">Nenhum dano</option>
                <option value="half">Metade do dano</option>
              </select>
            </label>
          </>
        )}
        <label>
          Tipo de dano
          <select
            value={action.damageType ?? ""}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].damageType = event.target.value
                  ? (event.target.value as ActorAction["damageType"])
                  : undefined;
              })
            }
          >
            <option value="">Não definido / revisar manualmente</option>
            {Object.entries(DAMAGE_LABELS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Uso no turno
          <select
            value={action.economy ?? "action"}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].economy = event.target.value as ActorAction["economy"];
              })
            }
          >
            <option value="action">Ação</option>
            <option value="bonus">Ação bônus</option>
            <option value="reaction">Reação</option>
            <option value="other">Outro / especial</option>
          </select>
        </label>
      </div>
      <label className="check-label">
        <input
          type="checkbox"
          checked={action.concentration ?? false}
          onChange={(event) =>
            mutate((next) => {
              next.actions[index].concentration = event.target.checked;
            })
          }
        />
        Exige concentração (substitui o efeito anterior)
      </label>
      <label>
        Consumir espaço de magia
        <select
          value={action.spellSlotLevel ?? ""}
          onChange={(event) =>
            mutate((next) => {
              next.actions[index].spellSlotLevel = event.target.value
                ? Number(event.target.value)
                : undefined;
            })
          }
        >
          <option value="">Sem consumo (ataque, truque ou uso livre)</option>
          {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((level) => (
            <option key={level} value={level}>
              Nível {level}
            </option>
          ))}
        </select>
      </label>
      <div className="editor-grid">
        <label>
          Consumir recurso
          <select
            value={action.resourceId ?? ""}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].resourceId = event.target.value || undefined;
                next.actions[index].resourceCost = undefined; // Default comes from the chosen pool.
              })
            }
          >
            <option value="">Sem recurso genérico</option>
            <option value="inspiration">Inspiração · {system.inspiration ? "1/1" : "0/1"}</option>
            {system.resources.map((resource) => (
              <option value={resource.id} key={resource.id}>
                {resource.name} · {resource.max - resource.used}/{resource.max}
              </option>
            ))}
          </select>
        </label>
        {action.resourceId && (
          <label>
            Custo
            <input
              type="number"
              min={1}
              max={1000}
              value={
                action.resourceCost ??
                system.resources.find((resource) => resource.id === action.resourceId)
                  ?.defaultCost ??
                1
              }
              onChange={(event) =>
                mutate((next) => {
                  next.actions[index].resourceCost = Math.max(1, Number(event.target.value));
                })
              }
            />
          </label>
        )}
      </div>
      <div className="editor-grid">
        <label>
          Consumir cargas de item/documento
          <select
            value={action.documentId ?? ""}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].documentId = event.target.value
                  ? Number(event.target.value)
                  : undefined;
                next.actions[index].chargeCost = event.target.value
                  ? (next.actions[index].chargeCost ?? 1)
                  : undefined;
              })
            }
          >
            <option value="">Sem consumo de cargas</option>
            {documents
              .filter((document) => document.charges)
              .map((document) => (
                <option value={document.id} key={document.id}>
                  {document.name} · {document.charges?.value}/{document.charges?.max}
                </option>
              ))}
          </select>
        </label>
        {action.documentId && (
          <label>
            Cargas gastas
            <input
              type="number"
              min={1}
              max={1000}
              value={action.chargeCost ?? 1}
              onChange={(event) =>
                mutate((next) => {
                  next.actions[index].chargeCost = Math.max(1, Number(event.target.value));
                })
              }
            />
          </label>
        )}
      </div>
      <details>
        <summary>Efeito automático da ação</summary>
        <label className="check-label">
          <input
            type="checkbox"
            checked={!!action.effect}
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].effect = event.target.checked
                  ? {
                      name: next.actions[index].name,
                      target: "targets",
                      trigger: "on-use",
                      duration: { unit: "rounds", remaining: 1 },
                      modifiers: [],
                      conditions: [],
                    }
                  : undefined;
              })
            }
          />
          Aplicar Active Effect ao executar
        </label>
        {action.effect && (
          <div className="editor-grid">
            <label>
              Nome do efeito
              <input
                value={action.effect.name}
                onChange={(event) =>
                  mutate((next) => {
                    if (next.actions[index].effect)
                      next.actions[index].effect.name = event.target.value;
                  })
                }
              />
            </label>
            <label>
              Alvo
              <select
                value={action.effect.target}
                onChange={(event) =>
                  mutate((next) => {
                    if (next.actions[index].effect)
                      next.actions[index].effect.target = event.target.value as "self" | "targets";
                  })
                }
              >
                <option value="targets">Alvos selecionados no mapa</option>
                <option value="self">A própria ficha</option>
              </select>
            </label>
            <label>
              Momento
              <select
                value={action.effect.trigger}
                onChange={(event) =>
                  mutate((next) => {
                    if (next.actions[index].effect)
                      next.actions[index].effect.trigger = event.target.value as
                        | "on-use"
                        | "on-hit"
                        | "on-failed-save";
                  })
                }
              >
                <option value="on-use">Ao usar</option>
                <option value="on-hit">Ao acertar</option>
                <option value="on-failed-save">Quando o alvo falhar no save</option>
              </select>
            </label>
            <label>
              Duração
              <select
                value={action.effect.duration.unit}
                onChange={(event) =>
                  mutate((next) => {
                    if (next.actions[index].effect) {
                      next.actions[index].effect.duration.unit = event.target
                        .value as ActorAction["effect"] extends infer E
                        ? E extends { duration: { unit: infer U } }
                          ? U
                          : never
                        : never;
                    }
                  })
                }
              >
                <option value="rounds">Rodadas</option>
                <option value="minutes">Minutos</option>
                <option value="hours">Horas</option>
                <option value="until-short-rest">Até descanso curto</option>
                <option value="until-long-rest">Até descanso longo</option>
                <option value="permanent">Permanente</option>
              </select>
            </label>
            {["rounds", "minutes", "hours"].includes(action.effect.duration.unit) && (
              <label>
                Restante
                <input
                  type="number"
                  min={0}
                  value={action.effect.duration.remaining ?? 1}
                  onChange={(event) =>
                    mutate((next) => {
                      if (next.actions[index].effect)
                        next.actions[index].effect.duration.remaining = Math.max(
                          0,
                          Number(event.target.value),
                        );
                    })
                  }
                />
              </label>
            )}
            <label>
              Condição opcional
              <input
                value={action.effect.conditions[0] ?? ""}
                placeholder="poisoned"
                onChange={(event) =>
                  mutate((next) => {
                    if (!next.actions[index].effect) return;
                    next.actions[index].effect.conditions = event.target.value
                      ? [event.target.value]
                      : [];
                  })
                }
              />
            </label>
            <label>
              Modificador
              <select
                value={action.effect.modifiers[0]?.path ?? ""}
                onChange={(event) =>
                  mutate((next) => {
                    if (!next.actions[index].effect) return;
                    next.actions[index].effect.modifiers = event.target.value
                      ? [{ path: event.target.value, mode: "add", value: 1 }]
                      : [];
                  })
                }
              >
                <option value="">Sem modificador numérico</option>
                <option value="ac">Classe de Armadura</option>
                <option value="speed">Deslocamento</option>
                <option value="roll.attack">Ataques</option>
                <option value="roll.damage">Dano</option>
                <option value="roll.save">Salvaguardas</option>
                <option value="roll.initiative">Iniciativa</option>
                <option value="spell.saveDc">CD de magia</option>
              </select>
            </label>
            {action.effect.modifiers[0] && (
              <>
                <label>
                  Operação
                  <select
                    value={action.effect.modifiers[0].mode}
                    onChange={(event) =>
                      mutate((next) => {
                        if (next.actions[index].effect?.modifiers[0])
                          next.actions[index].effect.modifiers[0].mode = event.target.value as
                            | "add"
                            | "multiply"
                            | "override";
                      })
                    }
                  >
                    <option value="add">Somar</option>
                    <option value="multiply">Multiplicar</option>
                    <option value="override">Substituir</option>
                  </select>
                </label>
                <label>
                  Valor
                  <input
                    value={action.effect.modifiers[0].value}
                    placeholder="1 ou d4"
                    onChange={(event) =>
                      mutate((next) => {
                        if (!next.actions[index].effect?.modifiers[0]) return;
                        const raw = event.target.value.trim();
                        next.actions[index].effect.modifiers[0].value = /^[-+]?\d+(?:\.\d+)?$/.test(
                          raw,
                        )
                          ? Number(raw)
                          : raw;
                      })
                    }
                  />
                </label>
              </>
            )}
          </div>
        )}
      </details>
      <label>
        URL do GIF/efeito (HTTPS)
        <input
          type="url"
          value={action.effectUrl ?? ""}
          placeholder="https://…/fireball.gif"
          onChange={(event) =>
            mutate((next) => {
              next.actions[index].effectUrl = event.target.value || undefined;
            })
          }
        />
      </label>
      <label>
        Descrição
        <textarea
          rows={2}
          value={action.description ?? ""}
          onChange={(event) =>
            mutate((next) => {
              next.actions[index].description = event.target.value || undefined;
            })
          }
        />
      </label>
    </section>
  );
}
