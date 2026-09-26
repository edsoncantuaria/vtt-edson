import type { Actor, ResourcePool } from "@vtt/core";
import { useRef, useState } from "react";
import { api } from "../../lib/api";
import { isManagerRole, useSession } from "../../store/session";

type ResourceEvent = {
  id: number;
  event: string;
  reason: string | null;
  resource_id: string | null;
  created_at: string;
  before: string;
  after: string;
};

const recoveryLabel = { none: "não recupera", one: "recupera 1", full: "recupera tudo" } as const;
const kindLabel: Record<string, string> = {
  "spell-slot": "Magia",
  inspiration: "Inspiração",
  rage: "Fúria",
  ki: "Ki",
  focus: "Foco",
  "channel-divinity": "Canalizar Divindade",
  homebrew: "Personalizado",
  "document-charge": "Carga de item",
};

function changes(event: ResourceEvent): string {
  try {
    const before = JSON.parse(event.before) as ResourcePool[];
    const after = JSON.parse(event.after) as ResourcePool[];
    const old = new Map(before.map((entry) => [entry.id, entry]));
    return (
      after
        .filter((entry) => old.get(entry.id)?.current !== entry.current)
        .map(
          (entry) =>
            `${entry.name}: ${old.get(entry.id)?.current ?? "—"} → ${entry.current}/${entry.max}`,
        )
        .join(" · ") || "Sem alteração quantitativa"
    );
  } catch {
    return "Histórico de quantidades indisponível";
  }
}

export function ResourcePoolStrip({
  actor,
  filter,
  canEdit,
  busy,
}: {
  actor: Actor;
  filter: "slots" | "other";
  canEdit: boolean;
  busy: boolean;
}) {
  const manager = isManagerRole(useSession((state) => state.role));
  const ruleset = useSession((state) => state.ruleset) ?? "5e-2014";
  const upsertActor = useSession((state) => state.upsertActor);
  const setError = useSession((state) => state.setError);
  const [adjusting, setAdjusting] = useState(false);
  const [reason, setReason] = useState("");
  const [reviewId, setReviewId] = useState<string | null>(null);
  const [reviewCurrent, setReviewCurrent] = useState(0);
  const [events, setEvents] = useState<ResourceEvent[] | null>(null);
  const pending = useRef<{ key: string; requestId: string } | null>(null);
  const pools: ResourcePool[] = actor.resourcePools ?? [
    ...Object.entries(actor.system.spells.slots).map(([level, slot]) => ({
      id: `slot:${level}`,
      actorId: actor.id,
      kind: "spell-slot",
      name: `Espaço nível ${level}`,
      current: slot.max - slot.used,
      max: slot.max,
      used: slot.used,
      source: "spells.slots",
      edition: ruleset,
      defaultCost: 1,
      recovery: { short: "none" as const, long: "full" as const },
    })),
    ...actor.system.resources.map((resource) => ({
      id: resource.id,
      actorId: actor.id,
      kind: resource.kind ?? "homebrew",
      name: resource.name,
      current: resource.max - resource.used,
      max: resource.max,
      used: resource.used,
      source: resource.source ?? "Ficha",
      edition: resource.edition ?? ruleset,
      defaultCost: resource.defaultCost ?? 1,
      recovery: resource.recovery ?? {
        short: resource.reset === "short" ? ("full" as const) : ("none" as const),
        long: resource.reset === "manual" ? ("none" as const) : ("full" as const),
      },
    })),
  ];
  const filtered = pools.filter((pool) =>
    filter === "slots" ? pool.kind === "spell-slot" && pool.max > 0 : pool.kind !== "spell-slot",
  );

  async function adjust(pool: ResourcePool, current: number) {
    if (
      busy ||
      adjusting ||
      !canEdit ||
      current < 0 ||
      current > pool.max ||
      current === pool.current ||
      (manager && !reason.trim())
    )
      return;
    const key = `${actor.id}:${pool.id}:${current}:${manager ? reason.trim() : ""}`;
    if (pending.current?.key !== key) pending.current = { key, requestId: crypto.randomUUID() };
    setAdjusting(true);
    setError(null);
    try {
      const result = await api<{ actor: Actor }>(`/actors/${actor.id}/resources/adjust`, {
        method: "POST",
        body: JSON.stringify({
          requestId: pending.current.requestId,
          resourceId: pool.id,
          current,
          ...(manager ? { reason: reason.trim() } : {}),
        }),
      });
      upsertActor(result.actor);
      pending.current = null;
      setReviewId(null);
      setReason("");
      setEvents(null);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível alterar o recurso.");
    } finally {
      setAdjusting(false);
    }
  }

  async function showHistory() {
    if (events !== null) {
      setEvents(null);
      return;
    }
    try {
      const result = await api<{ events: ResourceEvent[] }>(`/actors/${actor.id}/resources`);
      setEvents(result.events);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível consultar o histórico.");
    }
  }

  return (
    <div
      className="slot-list"
      aria-label={filter === "slots" ? "Espaços de magia" : "Recursos da ficha"}
    >
      {filtered.map((pool) => (
        <div key={pool.id}>
          <span>
            {pool.name}
            <small>
              {kindLabel[pool.kind] ?? pool.kind} · {pool.source} · {pool.edition} · custo padrão{" "}
              {pool.defaultCost} · descanso curto: {recoveryLabel[pool.recovery.short]} · longo:{" "}
              {recoveryLabel[pool.recovery.long]}
            </small>
          </span>
          <b>
            {pool.current}/{pool.max}
          </b>
          {canEdit && (
            <>
              <button
                disabled={
                  busy ||
                  adjusting ||
                  pool.current >= pool.max ||
                  (manager && !reason.trim()) ||
                  (pool.id === "inspiration" && !manager)
                }
                aria-label={`Recuperar ${pool.name}`}
                onClick={() => void adjust(pool, pool.current + 1)}
              >
                Recuperar
              </button>
              <button
                disabled={busy || adjusting || pool.current === 0 || (manager && !reason.trim())}
                aria-label={`Gastar ${pool.name}`}
                onClick={() => void adjust(pool, pool.current - 1)}
              >
                Gastar
              </button>
              {manager && (
                <button
                  disabled={busy || adjusting}
                  onClick={() => {
                    setReviewId(reviewId === pool.id ? null : pool.id);
                    setReviewCurrent(pool.current);
                  }}
                >
                  Ajustar quantidade
                </button>
              )}
            </>
          )}
          {manager && reviewId === pool.id && (
            <label>
              Disponível
              <input
                type="number"
                min={0}
                max={pool.max}
                value={reviewCurrent}
                onChange={(event) => setReviewCurrent(Number(event.target.value))}
              />
              <button
                disabled={
                  busy ||
                  adjusting ||
                  !reason.trim() ||
                  !Number.isInteger(reviewCurrent) ||
                  reviewCurrent < 0 ||
                  reviewCurrent > pool.max
                }
                onClick={() => void adjust(pool, reviewCurrent)}
              >
                Confirmar ajuste auditado
              </button>
            </label>
          )}
        </div>
      ))}
      {manager && canEdit && (
        <label>
          Motivo obrigatório dos ajustes do mestre
          <input
            value={reason}
            maxLength={240}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Decisão da mesa ou correção de recurso"
          />
        </label>
      )}
      {canEdit && (
        <section>
          <button type="button" disabled={adjusting} onClick={() => void showHistory()}>
            {events === null ? "Inspecionar histórico de recursos" : "Ocultar histórico"}
          </button>
          {events !== null && (
            <div aria-label="Histórico de recursos" className="sheet-items">
              {events.length === 0 && <small>Sem alterações registradas.</small>}
              {events.map((event) => (
                <small key={event.id}>
                  {event.event} · {changes(event)} · {event.reason ?? ""} ·{" "}
                  {new Date(event.created_at).toLocaleString("pt-BR")}
                </small>
              ))}
            </div>
          )}
        </section>
      )}
    </div>
  );
}
