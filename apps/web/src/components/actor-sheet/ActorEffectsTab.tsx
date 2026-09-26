import type { Actor } from "@vtt/core";
import { useRef, useState } from "react";
import { api } from "../../lib/api";
import { useSession } from "../../store/session";
import { CONDITION_LABELS, conditionLabel } from "../../lib/conditions";
import { publicAssetUrl } from "../../lib/assets";

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

export function ActorEffectsTab({
  actor,
  canEdit,
  manager = false,
}: {
  actor: Actor;
  canEdit: boolean;
  manager?: boolean;
}) {
  const upsertActor = useSession((state) => state.upsertActor);
  const setError = useSession((state) => state.setError);
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState("");
  const [sourceLabel, setSourceLabel] = useState("");
  const [iconUrl, setIconUrl] = useState("");
  const [visibility, setVisibility] = useState<"public" | "gm">("public");
  const [durationUnit, setDurationUnit] = useState("rounds");
  const [phase, setPhase] = useState("round");
  const [remaining, setRemaining] = useState(1);
  const [path, setPath] = useState("ac");
  const [mode, setMode] = useState("add");
  const [value, setValue] = useState(1);
  const [condition, setCondition] = useState("");
  const [editing, setEditing] = useState<number | null>(null);
  const [editName, setEditName] = useState("");
  const [editSource, setEditSource] = useState("");
  const [editIcon, setEditIcon] = useState("");
  const [editConditions, setEditConditions] = useState("");
  const [editRemaining, setEditRemaining] = useState(1);
  const [editPhase, setEditPhase] = useState<"round" | "start" | "end">("round");
  const [editVisibility, setEditVisibility] = useState<"public" | "gm">("public");
  const [editReason, setEditReason] = useState("");
  const [history, setHistory] = useState<
    Array<{
      id: number;
      event: string;
      effect_id: number;
      created_at: string;
      user_name: string | null;
      reason: string | null;
      before: string | null;
      after: string | null;
    }>
  >([]);
  const [historyOpen, setHistoryOpen] = useState(false);
  const restRequest = useRef<{ key: string; requestId: string } | null>(null);

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
          source_label: sourceLabel.trim() || "Ficha · decisão da mesa",
          icon_url: iconUrl.trim() || null,
          visibility,
          duration: {
            unit: durationUnit,
            ...(durationUnit === "rounds" ? { phase } : {}),
            ...(durationUnit === "rounds" || durationUnit === "minutes" || durationUnit === "hours"
              ? { remaining: Math.max(1, remaining) }
              : {}),
          },
          modifiers: value === 0 ? [] : [{ path, mode, value }],
          conditions: condition.trim() ? [condition.trim()] : [],
          active: true,
        }),
      }),
    );
    if (result) {
      upsertActor(result.actor);
      setName("");
      setCondition("");
      setValue(1);
      setSourceLabel("");
      setIconUrl("");
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
    const key = `${actor.id}:${rest}`;
    if (restRequest.current?.key !== key)
      restRequest.current = { key, requestId: crypto.randomUUID() };
    const requestId = restRequest.current.requestId;
    const result = await run(() =>
      api<{ actor: Actor }>(`/actors/${actor.id}/rest`, {
        method: "POST",
        body: JSON.stringify({ rest, requestId }),
      }),
    );
    if (result) {
      upsertActor(result.actor);
      restRequest.current = null;
    }
  }

  async function viewHistory() {
    if (historyOpen) {
      setHistoryOpen(false);
      return;
    }
    const result = await run(() =>
      api<{ events: typeof history }>(`/actors/${actor.id}/effect-history`),
    );
    if (result) {
      setHistory(result.events);
      setHistoryOpen(true);
    }
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
            {effect.icon_url && publicAssetUrl(effect.icon_url) && (
              <img src={publicAssetUrl(effect.icon_url)!} alt="" width={28} height={28} />
            )}
            <h4>{effect.name}</h4>
            <small>
              {effect.duration.unit === "rounds"
                ? `${effect.duration.remaining ?? 0} rodada(s) · ${effect.duration.phase === "start" ? "início do turno" : effect.duration.phase === "end" ? "fim do turno" : "fim da rodada"}`
                : effect.duration.unit.replaceAll("-", " ")}
              {!effect.active ? " · desativado" : ""}
            </small>
            {effect.source_label && <small>Origem: {effect.source_label}</small>}
            {manager && effect.visibility === "gm" && <small> · Oculto dos jogadores</small>}
            {effect.concentration_id && <small> · Vinculado à concentração</small>}
          </div>
          {!!effect.conditions.length && (
            <p>Condições: {effect.conditions.map(conditionLabel).join(", ")}</p>
          )}
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
              {manager && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    if (editing === effect.id) {
                      setEditing(null);
                      return;
                    }
                    setEditing(effect.id);
                    setEditName(effect.name);
                    setEditSource(effect.source_label ?? "");
                    setEditIcon(effect.icon_url ?? "");
                    setEditConditions(effect.conditions.join(", "));
                    setEditRemaining(effect.duration.remaining ?? 1);
                    setEditPhase(effect.duration.phase ?? "round");
                    setEditVisibility(effect.visibility);
                    setEditReason("");
                  }}
                >
                  {editing === effect.id ? "Cancelar revisão" : "Revisar efeito"}
                </button>
              )}
            </div>
          )}
          {manager && editing === effect.id && (
            <div className="editor-grid" aria-label={`Revisar ${effect.name}`}>
              <label>
                Nome
                <input
                  value={editName}
                  maxLength={160}
                  onChange={(event) => setEditName(event.target.value)}
                />
              </label>
              <label>
                Origem
                <input
                  value={editSource}
                  maxLength={160}
                  onChange={(event) => setEditSource(event.target.value)}
                />
              </label>
              <label>
                Ícone HTTPS
                <input
                  type="url"
                  value={editIcon}
                  onChange={(event) => setEditIcon(event.target.value)}
                />
              </label>
              <label>
                Condições (separadas por vírgula)
                <input
                  value={editConditions}
                  onChange={(event) => setEditConditions(event.target.value)}
                />
              </label>
              {effect.duration.unit === "rounds" && (
                <>
                  <label>
                    Rodadas restantes
                    <input
                      type="number"
                      min={1}
                      max={100000}
                      value={editRemaining}
                      onChange={(event) => setEditRemaining(Number(event.target.value))}
                    />
                  </label>
                  <label>
                    Expirar
                    <select
                      value={editPhase}
                      onChange={(event) =>
                        setEditPhase(event.target.value as "round" | "start" | "end")
                      }
                    >
                      <option value="round">Fim da rodada</option>
                      <option value="start">Início do turno</option>
                      <option value="end">Fim do turno</option>
                    </select>
                  </label>
                </>
              )}
              <label>
                Visibilidade
                <select
                  value={editVisibility}
                  onChange={(event) => setEditVisibility(event.target.value as "public" | "gm")}
                >
                  <option value="public">Pública</option>
                  <option value="gm">Somente mestre</option>
                </select>
              </label>
              <label>
                Motivo da revisão
                <input
                  value={editReason}
                  maxLength={240}
                  onChange={(event) => setEditReason(event.target.value)}
                  placeholder="Decisão da mesa"
                />
              </label>
              <button
                type="button"
                disabled={
                  busy ||
                  !editName.trim() ||
                  !editReason.trim() ||
                  (effect.duration.unit === "rounds" &&
                    (!Number.isInteger(editRemaining) || editRemaining < 1))
                }
                onClick={() =>
                  void patchEffect(effect.id, {
                    name: editName.trim(),
                    source_label: editSource.trim() || null,
                    icon_url: editIcon.trim() || null,
                    conditions: editConditions
                      .split(",")
                      .map((value) => value.trim())
                      .filter(Boolean),
                    visibility: editVisibility,
                    ...(effect.duration.unit === "rounds"
                      ? {
                          duration: {
                            ...effect.duration,
                            remaining: editRemaining,
                            phase: editPhase,
                          },
                        }
                      : {}),
                    reason: editReason.trim(),
                  }).then(() => setEditing(null))
                }
              >
                Salvar revisão auditada
              </button>
            </div>
          )}
        </article>
      ))}

      {manager && (
        <section>
          <button type="button" disabled={busy} onClick={() => void viewHistory()}>
            {historyOpen ? "Ocultar histórico" : "Inspecionar histórico dos efeitos"}
          </button>
          {historyOpen && (
            <div className="sheet-items" aria-label="Histórico auditado dos efeitos">
              {!history.length && <p>Nenhuma alteração registrada.</p>}
              {history.map((event) => (
                <details key={event.id}>
                  <summary>
                    #{event.effect_id} · {event.event} · {event.user_name ?? "Sistema"} ·{" "}
                    {new Date(event.created_at).toLocaleString("pt-BR")}
                  </summary>
                  {event.reason && <small>Motivo: {event.reason}</small>}
                  {(["before", "after"] as const).map((key) => {
                    const snapshot = event[key]
                      ? (JSON.parse(event[key]!) as {
                          name?: string;
                          active?: boolean;
                          duration?: { remaining?: number };
                          conditions?: string[];
                        })
                      : null;
                    return (
                      snapshot && (
                        <small key={key}>
                          {key === "before" ? "Antes" : "Depois"}: {snapshot.name ?? "Efeito"} ·{" "}
                          {snapshot.active ? "ativo" : "inativo"} ·{" "}
                          {snapshot.duration?.remaining ?? "—"} restante ·{" "}
                          {(snapshot.conditions ?? []).map(conditionLabel).join(", ")}
                        </small>
                      )
                    );
                  })}
                </details>
              ))}
            </div>
          )}
        </section>
      )}

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
            {durationUnit === "rounds" && (
              <label>
                Expiração
                <select value={phase} onChange={(event) => setPhase(event.target.value)}>
                  <option value="round">Fim da rodada</option>
                  <option value="start">Início do turno</option>
                  <option value="end">Fim do turno</option>
                </select>
              </label>
            )}
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
                placeholder="poisoned, restrained, prone…"
                list={`condition-list-${actor.id}`}
              />
              <datalist id={`condition-list-${actor.id}`}>
                {Object.entries(CONDITION_LABELS).map(([key, entry]) => (
                  <option key={key} value={key}>
                    {entry.label}
                  </option>
                ))}
              </datalist>
            </label>
            <label>
              Origem do efeito
              <input
                value={sourceLabel}
                maxLength={160}
                onChange={(event) => setSourceLabel(event.target.value)}
                placeholder="Poção, magia, decisão do mestre"
              />
            </label>
            <label>
              Ícone HTTPS (opcional)
              <input
                type="url"
                value={iconUrl}
                onChange={(event) => setIconUrl(event.target.value)}
                placeholder="https://…"
              />
            </label>
            {manager && (
              <label>
                Visibilidade
                <select
                  value={visibility}
                  onChange={(event) => setVisibility(event.target.value as "public" | "gm")}
                >
                  <option value="public">Pública</option>
                  <option value="gm">Somente mestre</option>
                </select>
              </label>
            )}
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
