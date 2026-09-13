import type { Actor, ActorSystem } from "@vtt/core";
import { Icon } from "../Icon";
import { ActorEffectsTab } from "./ActorEffectsTab";
import { ActionsTab, AttributesTab, EquipmentTab, SpellsTab, StoryTab } from "./ActorSheetTabs";
import { ACTOR_SHEET_TABS, type ActorSheetTab } from "./actorSheetTypes";

export function ActorQuickSheet({
  actor,
  ruleset,
  canEdit,
  busy,
  amount,
  setAmount,
  tab,
  setTab,
  mode,
  setMode,
  formula,
  roll,
  change,
  executeAction,
  onEdit,
  onDelete,
}: {
  actor: Actor;
  ruleset: "5e-2014" | "5e-2024";
  canEdit: boolean;
  busy: boolean;
  amount: number;
  setAmount: (value: number) => void;
  tab: ActorSheetTab;
  setTab: (tab: ActorSheetTab) => void;
  mode: string;
  setMode: (mode: string) => void;
  formula: (modifier: number) => string;
  roll: (formula: string, label: string) => Promise<void>;
  change: (change: (system: ActorSystem) => void) => Promise<void>;
  executeAction: (actionId: string) => Promise<void>;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <>
      <div className="character-title">
        <span className={`character-portrait character-portrait--${actor.type}`}>
          <Icon name={actor.type === "monster" ? "swords" : "shield"} size={27} />
        </span>
        <div>
          <h3>{actor.name}</h3>
          <p>
            {actor.system.bio.race ||
              (ruleset === "5e-2024" ? "Espécie não definida" : "Raça não definida")}{" "}
            · {actor.system.bio.class || "Sem classe"}
            <span>NÍVEL {actor.system.bio.level}</span>
          </p>
        </div>
      </div>
      {!canEdit && <p className="read-only">Ficha de outro participante · somente leitura</p>}
      <div className="character-vitals">
        <div className="health-vital">
          <span>
            <Icon name="heart" size={13} />
            PONTOS DE VIDA
          </span>
          <strong>
            {actor.system.hp.value}
            <small> / {actor.system.hp.max}</small>
          </strong>
          <div className="mini-health">
            <i
              style={{
                width: `${Math.max(0, Math.min(100, (actor.system.hp.value / Math.max(1, actor.system.hp.max)) * 100))}%`,
              }}
            />
          </div>
          {actor.system.hp.temp > 0 && <small>+{actor.system.hp.temp} temporários</small>}
        </div>
        <div>
          <Icon name="shield" size={17} />
          <strong>{actor.system.ac}</strong>
          <span>DEFESA</span>
        </div>
        <div>
          <Icon name="arrow" size={17} />
          <strong>
            {actor.system.speed}
            <small> ft</small>
          </strong>
          <span>MOVIMENTO</span>
        </div>
      </div>
      {canEdit && (
        <div className="health-actions">
          <button
            className="danger"
            disabled={busy || amount < 1}
            onClick={() =>
              void change((system) => {
                const absorbed = Math.min(system.hp.temp, amount);
                system.hp.temp -= absorbed;
                system.hp.value = Math.max(0, system.hp.value - (amount - absorbed));
              })
            }
          >
            Receber dano
          </button>
          <input
            aria-label="Quantidade de dano ou cura"
            type="number"
            min={1}
            max={999}
            value={amount}
            onChange={(event) => setAmount(Math.max(0, Number(event.target.value)))}
          />
          <button
            disabled={busy || amount < 1}
            onClick={() =>
              void change((system) => {
                system.hp.value = Math.min(system.hp.max, system.hp.value + amount);
              })
            }
          >
            Curar
          </button>
        </div>
      )}
      {actor.system.preparation && (
        <details className="action-resolution">
          <summary>
            Preparação da ficha ·{" "}
            {actor.system.preparation.tasks.filter((task) => !task.done).length} pendências
          </summary>
          <p>
            Verificação manual da mesa · {actor.system.preparation.source} ·{" "}
            {actor.system.preparation.edition.slice(-4)}. Reabra os itens se mudar classe, nível ou
            escolhas.
          </p>
          {actor.system.preparation.tasks.map((task, index) => (
            <label className="check-label" key={index}>
              <input
                type="checkbox"
                disabled={!canEdit || busy}
                checked={task.done}
                onChange={(event) =>
                  void change((system) => {
                    if (system.preparation)
                      system.preparation.tasks[index].done = event.target.checked;
                  })
                }
              />
              {task.text}
            </label>
          ))}
          <button disabled={!canEdit || busy} onClick={onEdit}>
            Revisar escolhas no editor
          </button>
        </details>
      )}
      {actor.system.concentration && (
        <div className="notice">
          <strong>Concentração: {actor.system.concentration.name}</strong>
          <button
            disabled={!canEdit || busy}
            onClick={() =>
              void change((system) => {
                system.concentration = null;
              })
            }
          >
            Encerrar concentração
          </button>
        </div>
      )}
      <div className="sheet-tabs">
        {ACTOR_SHEET_TABS.map((item) => (
          <button aria-pressed={tab === item} onClick={() => setTab(item)} key={item}>
            {item}
          </button>
        ))}
      </div>
      {tab === "Atributos" && (
        <AttributesTab
          actor={actor}
          canEdit={canEdit}
          busy={busy}
          mode={mode}
          setMode={setMode}
          formula={formula}
          roll={roll}
        />
      )}
      {tab === "Equipamento" && (
        <EquipmentTab
          actor={actor}
          canEdit={canEdit}
          busy={busy}
          formula={formula}
          roll={roll}
          change={change}
        />
      )}
      {tab === "Ações" && (
        <ActionsTab
          actor={actor}
          canEdit={canEdit}
          busy={busy}
          change={change}
          executeAction={executeAction}
        />
      )}
      {tab === "Magias" && (
        <SpellsTab actor={actor} canEdit={canEdit} busy={busy} change={change} />
      )}
      {tab === "Efeitos" && <ActorEffectsTab actor={actor} canEdit={canEdit} />}
      {tab === "História" && <StoryTab actor={actor} canEdit={canEdit} onDelete={onDelete} />}
    </>
  );
}
