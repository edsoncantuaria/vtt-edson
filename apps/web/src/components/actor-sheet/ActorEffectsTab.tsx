import type { Actor } from "@vtt/core";
import { useState } from "react";
import { api } from "../../lib/api";
import { useSession } from "../../store/session";

const MODIFIER_PATHS = [
  ["ac", "Classe de Armadura"],
  ["speed", "Deslocamento"],
  ["proficiencyBonus", "Bônus de proficiência"],
  ["abilities.str.score", "Força"],
  ["abilities.dex.score", "Destreza"],
  ["abilities.con.score", "Constituição"],
  ["abilities.int.score", "Inteligência"],
  ["abilities.wis.score", "Sabedoria"],
  ["abilities.cha.score", "Carisma"],
  ["senses.darkvision", "Visão no escuro"],
  ["roll.attack", "Rolagens de ataque"],
  ["roll.damage", "Rolagens de dano"],
  ["roll.save", "Salvaguardas"],
  ["roll.initiative", "Iniciativa"],
  ["spell.saveDc", "CD de magia"],
] as const;

export function ActorEffectsTab({ actor, canEdit }: { actor: Actor; canEdit: boolean }) {
  const upsertActor = useSession((state) => state.upsertActor);
  const setError = useSession((state) => state.setError);
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState("");
  const [durationUnit, setDurationUnit] = useState("rounds");
  const [remaining, setRemaining] = useState(1);
  const [path, setPath] = useState("ac");
  const [mode, setMode] = useState("add");
  const [value, setValue] = useState(1);
  const [condition, setCondition] = useState("");

  async function run<T>(operation: () => Promise<T>): Promise<T | undefined> {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      return await operation();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível atualizar o efeito.");
    } finally {
      setBusy(false);
    }
  }

  async function createEffect() {
    if (!name.trim()) return;
    const result = await run(() =>
      api<{ actor: Actor }>(`/actors/${actor.id}/effects`, {
        method: "POST",
        body: JSON.stringify({
          name: name.trim(),
          duration: {
            unit: durationUnit,
            ...(durationUnit === "rounds" || durationUnit === "minutes" || durationUnit === "hours"
              ? { remaining: Math.max(0, remaining) }
              : {}),
          },
          modifiers: value === 0 ? [] : [{ path, mode, value }],
          conditions: condition.trim() ? [condition.trim()] : [],
          metadata: { source: "sheet" },
          active: true,
        }),
      }),
    );
    if (result) {
      upsertActor(result.actor);
      setName("");
      setCondition("");
      setValue(1);
    }
  }

  async function patchEffect(effectId: number, data: object) {
    const result = await run(() =>
      api<{ actor: Actor }>(`/active-effects/${effectId}`, {
        method: "PATCH",
        body: JSON.stringify(data),
      }),
    );
    if (result) upsertActor(result.actor);
  }

  async function deleteEffect(effectId: number) {
    const result = await run(() =>
      api<{ actor: Actor }>(`/active-effects/${effectId}`, { method: "DELETE" }),
    );
    if (result) upsertActor(result.actor);
  }

  async function rest(rest: "short" | "long") {
    const result = await run(() =>
      api<{ actor: Actor }>(`/actors/${actor.id}/rest`, {
        method: "POST",
        body: JSON.stringify({ rest }),
      }),
    );
    if (result) upsertActor(result.actor);
  }

  return (
    <div className="sheet-items">
      <div className="item-actions">
        <button disabled={!canEdit || busy} onClick={() => void rest("short")}>
          Descanso curto
        </button>
        <button disabled={!canEdit || busy} onClick={() => void rest("long")}>
          Descanso longo
        </button>
      </div>

      {!actor.activeEffects.length && (
        <p className="panel-hint">
          Nenhum efeito ativo. Bônus, penalidades e condições temporárias aparecem aqui.
        </p>
      )}
      {actor.activeEffects.map((effect) => (
        <article key={effect.id}>
          <div>
            <h4>{effect.name}</h4>
            <small>
              {effect.duration.unit === "rounds"
                ? `${effect.duration.remaining ?? 0} rodada(s)`
                : effect.duration.unit.replaceAll("-", " ")}
              {!effect.active ? " · desativado" : ""}
            </small>
          </div>
          {!!effect.conditions.length && <p>Condições: {effect.conditions.join(", ")}</p>}
          {!!effect.modifiers.length && (
            <p>
              {effect.modifiers
                .map((modifier) => `${modifier.path} ${modifier.mode} ${modifier.value}`)
                .join(" · ")}
            </p>
          )}
          {canEdit && (
            <div className="item-actions">
              <button
                disabled={busy}
                onClick={() => void patchEffect(effect.id, { active: !effect.active })}
              >
                {effect.active ? "Desativar" : "Ativar"}
              </button>
              <button
                className="danger"
                disabled={busy}
                onClick={() => void deleteEffect(effect.id)}
              >
                Remover
              </button>
            </div>
          )}
        </article>
      ))}

      {canEdit && (
        <details>
          <summary>Novo efeito</summary>
          <div className="editor-grid">
            <label>
              Nome
              <input
                value={name}
                maxLength={160}
                onChange={(event) => setName(event.target.value)}
              />
            </label>
            <label>
              Duração
              <select
                value={durationUnit}
                onChange={(event) => setDurationUnit(event.target.value)}
              >
                <option value="rounds">Rodadas</option>
                <option value="minutes">Minutos</option>
                <option value="hours">Horas</option>
                <option value="until-short-rest">Até descanso curto</option>
                <option value="until-long-rest">Até descanso longo</option>
                <option value="permanent">Permanente</option>
              </select>
            </label>
            {["rounds", "minutes", "hours"].includes(durationUnit) && (
              <label>
                Restante
                <input
                  type="number"
                  min={0}
                  value={remaining}
                  onChange={(event) => setRemaining(Number(event.target.value))}
                />
              </label>
            )}
            <label>
              Modifica
              <select value={path} onChange={(event) => setPath(event.target.value)}>
                {MODIFIER_PATHS.map(([id, label]) => (
                  <option value={id} key={id}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Operação
              <select value={mode} onChange={(event) => setMode(event.target.value)}>
                <option value="add">Somar</option>
                <option value="multiply">Multiplicar</option>
                <option value="override">Substituir</option>
              </select>
            </label>
            <label>
              Valor
              <input
                type="number"
                value={value}
                onChange={(event) => setValue(Number(event.target.value))}
              />
            </label>
            <label>
              Condição opcional
              <input
                value={condition}
                maxLength={120}
                onChange={(event) => setCondition(event.target.value)}
                placeholder="blessed, poisoned…"
              />
            </label>
          </div>
          <button
            className="primary"
            disabled={busy || !name.trim()}
            onClick={() => void createEffect()}
          >
            Aplicar efeito
          </button>
        </details>
      )}
    </div>
  );
}
