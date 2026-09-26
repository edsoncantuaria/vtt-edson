import { useEffect, useState } from "react";
import type { Actor, ChatMessage } from "@vtt/core";
import { isManagerRole } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import { healingTargets } from "../lib/healingTargets";

type HealingStatus = {
  preview: {
    rollId: string;
    rolled: number;
    restored: number;
    excess: number;
    steps: string[];
    sources: string[];
    current: number;
    max: number;
    blockedReason?: string | null;
  };
  application: null | {
    operationId: number;
    amount: number;
    confirmedBy: number;
    undone: boolean;
    resolution?: {
      requested: number;
      excess: number;
      rolled: number;
      reason: string | null;
      steps: string[];
      sources: string[];
    };
    before: { value: number };
    after: { value: number };
  };
  actionUndone: boolean;
};

/** The target owner (or GM) confirms a heal; rendering an action never edits HP. */
export function HealingApplication({ message }: { message: ChatMessage }) {
  const { actors, role, user, sceneId, setError, upsertActor } = useSession();
  const allowed = message.targetActorIds?.length ? message.targetActorIds : [message.sourceActorId];
  const eligible = healingTargets(actors, allowed, role, user?.id);
  const [open, setOpen] = useState(false);
  const [target, setTarget] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [exact, setExact] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState<HealingStatus | null>(null);
  const path = `/scenes/${sceneId}/actions/${message.id}/heal`;

  useEffect(() => {
    if (!open || target || eligible.length !== 1) return;
    setTarget(String(eligible[0].id));
  }, [open, target, eligible]);

  useEffect(() => {
    if (!open || !target || !sceneId) return;
    let active = true;
    setLoading(true);
    setStatus(null);
    api<HealingStatus>(`${path}?actorId=${target}`)
      .then((result) => {
        if (active) setStatus(result);
      })
      .catch((error) => {
        if (active) setError(error instanceof Error ? error.message : "Falha ao consultar a cura.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [open, target, sceneId, path, setError]);

  async function apply(undo = false) {
    if (!sceneId || !target || busy) return;
    setBusy(true);
    try {
      const result = await api<HealingStatus & { actor: Actor }>(path, {
        method: "POST",
        body: JSON.stringify({
          actorId: Number(target),
          ...(undo
            ? { undo: true }
            : {
                confirmed: true,
                ...(exact !== "" ? { amountOverride: Number(exact), reason: reason.trim() } : {}),
              }),
        }),
      });
      upsertActor(result.actor);
      setStatus(result);
      setConfirmed(false);
      setExact("");
      setReason("");
    } catch (error) {
      setError(error instanceof Error ? error.message : "Falha ao confirmar a cura.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <details className="action-resolution" onToggle={(event) => setOpen(event.currentTarget.open)}>
      <summary>Resolver cura · confirmação do alvo ou mestre</summary>
      {!eligible.length && (
        <p>A confirmação desta cura pertence ao responsável pelo alvo ou ao mestre.</p>
      )}
      {!!eligible.length && (
        <fieldset disabled={busy}>
          <label>
            Alvo da cura
            <select
              value={target}
              onChange={(event) => {
                setTarget(event.target.value);
                setConfirmed(false);
                setStatus(null);
              }}
            >
              <option value="">Selecione uma ficha</option>
              {eligible.map((actor) => (
                <option key={actor.id} value={actor.id}>
                  {actor.name}
                </option>
              ))}
            </select>
          </label>
          {loading && <p role="status">Consultando cura…</p>}
          {status && (
            <div className="resolution-result" role="status">
              <strong>Cura rolada: {status.preview.rolled} PV</strong>
              <small>
                PV atuais {status.preview.current}/{status.preview.max}; recuperação possível:{" "}
                {status.preview.restored} PV; excedente {status.preview.excess}. Rolagem{" "}
                {status.preview.rollId.slice(0, 8)}.
              </small>
              {isManagerRole(role) &&
                status.preview.steps.map((step, index) => <small key={index}>{step}</small>)}
              {isManagerRole(role) &&
                status.preview.sources.map((source, index) => (
                  <small key={index}>Fonte: {source}</small>
                ))}
              {status.preview.blockedReason && (
                <small role="alert">{status.preview.blockedReason}</small>
              )}
              {status.application && (
                <small>
                  {status.application.undone
                    ? "Cura desfeita"
                    : `Cura confirmada: +${status.application.amount} PV · operação #${status.application.operationId}`}
                </small>
              )}
              {isManagerRole(role) &&
                status.application?.resolution?.steps.map((step, index) => (
                  <small key={index}>Confirmado: {step}</small>
                ))}
              {isManagerRole(role) &&
                status.application?.resolution?.sources.map((source, index) => (
                  <small key={index}>Fonte: {source}</small>
                ))}
              {status.application?.resolution?.reason && (
                <small>Decisão registrada: {status.application.resolution.reason}</small>
              )}
              {status.actionUndone && <small>A ação foi desfeita.</small>}
            </div>
          )}
          {status && !status.application && !status.actionUndone && (
            <>
              {isManagerRole(role) && (
                <div>
                  <label>
                    Cura exata decidida pelo mestre (opcional)
                    <input
                      type="number"
                      min={0}
                      max={100000}
                      value={exact}
                      onChange={(event) => setExact(event.target.value)}
                      placeholder="Vazio: usar cura rolada"
                    />
                  </label>
                  {exact !== "" && (
                    <>
                      <small>
                        Prévia do ajuste: +
                        {Math.min(
                          Math.max(0, status.preview.max - status.preview.current),
                          Math.max(0, Number(exact) || 0),
                        )}{" "}
                        PV efetivos, sem alterar PV temporários.
                      </small>
                      <label>
                        Motivo obrigatório do ajuste
                        <input
                          value={reason}
                          maxLength={240}
                          onChange={(event) => setReason(event.target.value)}
                          placeholder="Decisão da mesa"
                        />
                      </label>
                    </>
                  )}
                </div>
              )}
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={confirmed}
                  onChange={(event) => setConfirmed(event.target.checked)}
                />
                Confirmo a aplicação desta cura.
              </label>
              <button
                className="primary"
                disabled={
                  !confirmed ||
                  loading ||
                  !!status.preview.blockedReason ||
                  (exact !== "" &&
                    (!Number.isInteger(Number(exact)) ||
                      Number(exact) < 0 ||
                      Number(exact) > 100000 ||
                      !reason.trim()))
                }
                onClick={() => void apply()}
              >
                Aplicar cura
              </button>
            </>
          )}
          {status?.application && !status.application.undone && (
            <button disabled={loading} onClick={() => void apply(true)}>
              Desfazer cura, se os PV não mudaram
            </button>
          )}
        </fieldset>
      )}
    </details>
  );
}
