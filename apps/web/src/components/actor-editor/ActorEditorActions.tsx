import { ABILITY_LABELS, DAMAGE_LABELS, type ActorAction, type ActorSystem } from "@vtt/core";
import { Icon } from "../Icon";
import type { MutateActorSystem } from "./ActorEditorFields";

export function ActionsSection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
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
            <label>
              Recupera
              <select
                value={resource.reset}
                onChange={(event) =>
                  mutate((next) => {
                    next.resources[index].reset = event.target.value as "short" | "long" | "manual";
                  })
                }
              >
                <option value="short">Descanso curto</option>
                <option value="long">Descanso longo</option>
                <option value="manual">Manual</option>
              </select>
            </label>
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
              });
            })
          }
        >
          <Icon name="plus" size={16} />
          Adicionar recurso
        </button>
      </details>
      {system.actions.map((action, index) => (
        <ActionEditor
          key={action.id}
          action={action}
          index={index}
          system={system}
          mutate={mutate}
        />
      ))}
      <button
        type="button"
        onClick={() =>
          mutate((next) => {
            next.actions.push({
              id: crypto.randomUUID(),
              name: "Nova ação",
              kind: "attack",
              attackFormula: "1d20+0",
            });
          })
        }
      >
        <Icon name="plus" size={16} />
        Adicionar ação
      </button>
    </>
  );
}

function ActionEditor({
  action,
  index,
  system,
  mutate,
}: {
  action: ActorAction;
  index: number;
  system: ActorSystem;
  mutate: MutateActorSystem;
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
                next.actions[index].kind = event.target.value as "attack" | "spell";
              })
            }
          >
            <option value="attack">Ataque</option>
            <option value="spell">Magia</option>
          </select>
        </label>
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
          Fórmula de dano
          <input
            value={action.damageFormula ?? ""}
            placeholder="8d6"
            onChange={(event) =>
              mutate((next) => {
                next.actions[index].damageFormula = event.target.value || undefined;
              })
            }
          />
        </label>
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
                next.actions[index].resourceCost = event.target.value
                  ? (next.actions[index].resourceCost ?? 1)
                  : undefined;
              })
            }
          >
            <option value="">Sem recurso genérico</option>
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
              value={action.resourceCost ?? 1}
              onChange={(event) =>
                mutate((next) => {
                  next.actions[index].resourceCost = Math.max(1, Number(event.target.value));
                })
              }
            />
          </label>
        )}
      </div>
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
