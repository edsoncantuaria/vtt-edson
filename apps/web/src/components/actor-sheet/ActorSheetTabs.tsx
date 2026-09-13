import {
  ABILITY_LABELS,
  SKILL_ABILITY,
  SKILL_LABELS,
  abilityModifier,
  formatModifier,
  type Ability,
  type Actor,
  type ActorSystem,
  type SkillKey,
} from "@vtt/core";
import { Icon } from "../Icon";

const ABILITIES: Ability[] = ["str", "dex", "con", "int", "wis", "cha"];
const ECONOMY_ORDER = ["action", "bonus", "reaction", "other"] as const;
const ECONOMY_LABEL = {
  action: "Ação",
  bonus: "Ação bônus",
  reaction: "Reação",
  other: "Especial",
} as const;

type ChangeActor = (change: (system: ActorSystem) => void) => Promise<void>;
type Roll = (formula: string, label: string) => Promise<void>;

export function AttributesTab({
  actor,
  canEdit,
  busy,
  mode,
  setMode,
  formula,
  roll,
}: {
  actor: Actor;
  canEdit: boolean;
  busy: boolean;
  mode: string;
  setMode: (mode: string) => void;
  formula: (modifier: number) => string;
  roll: Roll;
}) {
  return (
    <>
      <label className="roll-mode">
        Rolar testes com
        <select value={mode} onChange={(event) => setMode(event.target.value)}>
          <option value="normal">Rolagem normal</option>
          <option value="advantage">Vantagem</option>
          <option value="disadvantage">Desvantagem</option>
        </select>
      </label>
      <div className="ability-grid">
        {ABILITIES.map((ability) => {
          const modifier = abilityModifier(actor.system.abilities[ability].score);
          return (
            <button
              key={ability}
              title={`Rolar teste de ${ABILITY_LABELS[ability]}`}
              disabled={busy || !canEdit}
              onClick={() => void roll(formula(modifier), ABILITY_LABELS[ability])}
            >
              <span>{ABILITY_LABELS[ability]}</span>
              <strong>{formatModifier(modifier)}</strong>
              <small>{actor.system.abilities[ability].score}</small>
            </button>
          );
        })}
      </div>
      <h4 className="sheet-section-title">
        Salvaguardas <span>Proficiência {formatModifier(actor.system.proficiencyBonus)}</span>
      </h4>
      <div className="save-grid">
        {ABILITIES.map((ability) => {
          const modifier =
            actor.system.saves[ability]?.bonus ??
            abilityModifier(actor.system.abilities[ability].score) +
              (actor.system.saves[ability]?.proficient ? actor.system.proficiencyBonus : 0);
          return (
            <button
              key={ability}
              disabled={busy || !canEdit}
              onClick={() =>
                void roll(formula(modifier), `Salvaguarda de ${ABILITY_LABELS[ability]}`)
              }
            >
              <span>{ABILITY_LABELS[ability]}</span>
              <b>{formatModifier(modifier)}</b>
            </button>
          );
        })}
      </div>
      <h4 className="sheet-section-title">
        Perícias <span>Toque para rolar</span>
      </h4>
      <div className="skill-list">
        {(Object.keys(SKILL_ABILITY) as SkillKey[]).map((skillKey) => {
          const skill = actor.system.skills[skillKey];
          const modifier =
            abilityModifier(actor.system.abilities[SKILL_ABILITY[skillKey]].score) +
            (skill?.expertise
              ? actor.system.proficiencyBonus * 2
              : skill?.proficient
                ? actor.system.proficiencyBonus
                : 0);
          return (
            <button
              key={skillKey}
              disabled={busy || !canEdit}
              onClick={() => void roll(formula(modifier), `Perícia: ${SKILL_LABELS[skillKey]}`)}
            >
              <i
                className={skill?.expertise ? "expertise" : skill?.proficient ? "proficient" : ""}
              />
              <span>{SKILL_LABELS[skillKey]}</span>
              <b>{formatModifier(modifier)}</b>
              <Icon name="dice" size={14} />
            </button>
          );
        })}
      </div>
    </>
  );
}

export function EquipmentTab({
  actor,
  canEdit,
  busy,
  formula,
  roll,
  change,
}: {
  actor: Actor;
  canEdit: boolean;
  busy: boolean;
  formula: (modifier: number) => string;
  roll: Roll;
  change: ChangeActor;
}) {
  return (
    <div className="sheet-items">
      {!actor.system.inventory.length && (
        <p className="panel-hint">
          A mochila está vazia. Adicione itens pelo compêndio ou pela edição da ficha.
        </p>
      )}
      {actor.system.inventory.map((item) => (
        <article key={item.id}>
          <div>
            <h4>{item.name}</h4>
            <small>
              {item.quantity} un. {item.equipped ? "· Equipado" : ""}
            </small>
          </div>
          {item.description && <p>{item.description}</p>}
          <div className="item-actions">
            {item.attackBonus !== undefined && (
              <button
                disabled={!canEdit || busy}
                onClick={() => void roll(formula(item.attackBonus!), `${item.name} · ataque`)}
              >
                <Icon name="swords" size={14} />
                Atacar {formatModifier(item.attackBonus)}
              </button>
            )}
            {item.damage && (
              <button
                disabled={!canEdit || busy}
                onClick={() => void roll(item.damage!, `${item.name} · dano`)}
              >
                Dano {item.damage}
              </button>
            )}
            {canEdit && (
              <button
                disabled={busy}
                onClick={() =>
                  void change((system) => {
                    const target = system.inventory.find((entry) => entry.id === item.id);
                    if (target) target.equipped = !target.equipped;
                  })
                }
              >
                {item.equipped ? "Guardar" : "Equipar"}
              </button>
            )}
          </div>
        </article>
      ))}
      <div className="currency-row">
        {Object.entries(actor.system.currency).map(([coin, amount]) => (
          <span key={coin}>
            <b>{amount}</b>
            {
              { cp: "PC", sp: "PP", ep: "PE", gp: "PO", pp: "PL" }[
                coin as "cp" | "sp" | "ep" | "gp" | "pp"
              ]
            }
          </span>
        ))}
      </div>
    </div>
  );
}

export function ActionsTab({
  actor,
  canEdit,
  busy,
  change,
  executeAction,
}: {
  actor: Actor;
  canEdit: boolean;
  busy: boolean;
  change: ChangeActor;
  executeAction: (actionId: string) => Promise<void>;
}) {
  const sortedActions = [...actor.system.actions].sort(
    (left, right) =>
      ECONOMY_ORDER.indexOf(left.economy ?? "action") -
      ECONOMY_ORDER.indexOf(right.economy ?? "action"),
  );

  return (
    <div className="sheet-items">
      {!actor.system.actions.length && (
        <p className="panel-hint">
          Crie ataques e magias no editor da ficha. Cada ação pode usar um modificador na fórmula,
          como <code>1d20+7</code>, e um GIF HTTPS para aparecer na mesa.
        </p>
      )}
      <p className="panel-hint">
        Alvos, salvaguardas e dano são resolvidos no chat. Confirme reações e exceções antes de
        aplicar. A economia do turno fica sob controle da mesa.
      </p>
      {!!actor.system.resources.length && (
        <div className="slot-list">
          {actor.system.resources.map((resource) => (
            <div key={resource.id}>
              <span>
                {resource.name}
                <small>
                  {resource.reset === "short"
                    ? "descanso curto"
                    : resource.reset === "long"
                      ? "descanso longo"
                      : "recuperação manual"}
                </small>
              </span>
              <b>
                {Math.max(0, resource.max - resource.used)}/{resource.max}
              </b>
              {canEdit && (
                <>
                  <button
                    disabled={busy || resource.used === 0}
                    onClick={() =>
                      void change((system) => {
                        const item = system.resources.find((entry) => entry.id === resource.id);
                        if (item) item.used = Math.max(0, item.used - 1);
                      })
                    }
                  >
                    Recuperar
                  </button>
                  <button
                    disabled={busy || resource.used >= resource.max}
                    onClick={() =>
                      void change((system) => {
                        const item = system.resources.find((entry) => entry.id === resource.id);
                        if (item) item.used = Math.min(item.max, item.used + 1);
                      })
                    }
                  >
                    Gastar
                  </button>
                </>
              )}
            </div>
          ))}
        </div>
      )}
      {sortedActions.map((action) => (
        <article key={action.id}>
          <div>
            <h4>{action.name}</h4>
            <small>
              {ECONOMY_LABEL[action.economy ?? "action"]} ·{" "}
              {action.kind === "spell" ? "Magia" : "Ataque"} ·{" "}
              {action.attackFormula ?? action.damageFormula}
            </small>
          </div>
          {action.saveAbility && (
            <small>
              Salvaguarda de {ABILITY_LABELS[action.saveAbility]} · CD{" "}
              {action.saveDc ??
                8 +
                  abilityModifier(actor.system.abilities[actor.system.spellcastingAbility].score) +
                  actor.system.proficiencyBonus}{" "}
              · {action.saveEffect === "half" ? "metade no sucesso" : "nenhum dano no sucesso"}
            </small>
          )}
          {action.concentration && <small>Exige concentração; encerra a anterior.</small>}
          {action.spellSlotLevel && (
            <small>Consome 1 espaço de nível {action.spellSlotLevel}.</small>
          )}
          {action.resourceId && (
            <small>
              Consome {action.resourceCost ?? 1} de{" "}
              {actor.system.resources.find((resource) => resource.id === action.resourceId)?.name ??
                "recurso configurado"}
              .
            </small>
          )}
          {action.description && <p>{action.description}</p>}
          <button
            className="primary"
            disabled={!canEdit || busy}
            onClick={() => void executeAction(action.id)}
          >
            <Icon name={action.kind === "spell" ? "spark" : "swords"} size={15} />
            {action.kind === "spell" ? "Conjurar" : "Atacar"}
          </button>
        </article>
      ))}
    </div>
  );
}

export function SpellsTab({
  actor,
  canEdit,
  busy,
  change,
}: {
  actor: Actor;
  canEdit: boolean;
  busy: boolean;
  change: ChangeActor;
}) {
  return (
    <div className="sheet-items">
      <h4 className="sheet-section-title">
        Espaços de magia <span>Livres / total</span>
      </h4>
      <div className="slot-list">
        {Object.entries(actor.system.spells.slots)
          .filter(([, slot]) => slot.max > 0)
          .map(([level, slot]) => (
            <div key={level}>
              <span>{level}º círculo</span>
              <b>
                {slot.max - slot.used}/{slot.max}
              </b>
              <button
                disabled={!canEdit || busy || slot.used === 0}
                aria-label={`Recuperar espaço do círculo ${level}`}
                onClick={() =>
                  void change((system) => {
                    system.spells.slots[level].used--;
                  })
                }
              >
                <Icon name="plus" size={14} />
              </button>
              <button
                disabled={!canEdit || busy || slot.used >= slot.max}
                onClick={() =>
                  void change((system) => {
                    system.spells.slots[level].used++;
                  })
                }
              >
                Gastar
              </button>
            </div>
          ))}
      </div>
      {!actor.system.spells.known.length && (
        <p className="panel-hint">Adicione suas magias pelo compêndio ou edite a ficha.</p>
      )}
      {actor.system.spells.known.map((spell) => (
        <article key={spell.id}>
          <div>
            <h4>{spell.name}</h4>
            <small>{spell.level === 0 ? "Truque" : `${spell.level}º círculo`}</small>
          </div>
          {spell.description && <p>{spell.description}</p>}
          <button
            disabled={!canEdit || busy}
            aria-pressed={spell.prepared}
            onClick={() =>
              void change((system) => {
                const target = system.spells.known.find((entry) => entry.id === spell.id);
                if (target) target.prepared = !target.prepared;
              })
            }
          >
            {spell.prepared ? "Preparada" : "Preparar magia"}
          </button>
        </article>
      ))}
    </div>
  );
}

export function StoryTab({
  actor,
  canEdit,
  onDelete,
}: {
  actor: Actor;
  canEdit: boolean;
  onDelete: () => void;
}) {
  return (
    <div className="character-story">
      <dl>
        <dt>Antecedente</dt>
        <dd>{actor.system.bio.background || "Não definido"}</dd>
        <dt>Alinhamento</dt>
        <dd>{actor.system.bio.alignment || "Não definido"}</dd>
        <dt>Idiomas</dt>
        <dd>{actor.system.languages.join(", ") || "Nenhum"}</dd>
        <dt>Visão no escuro</dt>
        <dd>{actor.system.senses.darkvision} ft</dd>
      </dl>
      {actor.system.features.map((feature) => (
        <section key={feature.id}>
          <h4>{feature.name}</h4>
          <p>{feature.description}</p>
        </section>
      ))}
      <section>
        <h4>Notas da personagem</h4>
        <p>{actor.system.bio.notes || "Uma história ainda por escrever."}</p>
      </section>
      {canEdit && (
        <button className="danger" onClick={onDelete}>
          <Icon name="trash" size={14} />
          Excluir ficha
        </button>
      )}
    </div>
  );
}
