import { useEffect, useRef, useState } from "react";
import { ABILITY_LABELS, type ChatMessage } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import {
  batchReadyIds,
  pendingNpcIds,
  unresolvedSaveNames,
  type SaveBatchStatus,
} from "../lib/saveBatch";

/** GM-only batch review. Individual players still roll their own sheets in DamageApplication. */
export function SaveBatchResolution({ message }: { message: ChatMessage }) {
  const sceneId = useSession((state) => state.sceneId);
  const setError = useSession((state) => state.setError);
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState<SaveBatchStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState("normal");
  const [bonus, setBonus] = useState(0);
  const [decisionFor, setDecisionFor] = useState<number | null>(null);
  const [reason, setReason] = useState("");
  const [note, setNote] = useState("");
  const pending = useRef<{ key: string; id: string } | null>(null);
  const base = `/scenes/${sceneId}/actions/${message.id}`;

  useEffect(() => {
    if (!open || !sceneId) return;
    let active = true;
    const refresh = async () => {
      try {
        const next = await api<SaveBatchStatus>(`${base}/save-batch`);
        if (active) setStatus(next);
      } catch (error) {
        if (active)
          setError(error instanceof Error ? error.message : "Falha ao consultar salvaguardas.");
      }
    };
    void refresh();
    const timer = window.setInterval(() => {
      if (!document.hidden && !busy) void refresh();
    }, 5000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [base, busy, open, sceneId, setError]);

  async function submit(kind: "save-batch" | "resolve-batch", body: object, label: string) {
    if (busy || !sceneId) return;
    const key = JSON.stringify([sceneId, message.id, kind, body]);
    if (pending.current?.key !== key) pending.current = { key, id: crypto.randomUUID() };
    setBusy(true);
    try {
      await api(`${base}/${kind}`, {
        method: "POST",
        body: JSON.stringify({ ...body, requestId: pending.current.id }),
      });
      pending.current = null;
      setStatus(await api<SaveBatchStatus>(`${base}/save-batch`));
      setNote(label);
      setDecisionFor(null);
      setReason("");
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Não foi possível concluir a operação agrupada.",
      );
    } finally {
      setBusy(false);
    }
  }

  const rows = status?.rows ?? [];
  const npcs = pendingNpcIds(rows);
  const ready = batchReadyIds(rows);
  const unresolved = unresolvedSaveNames(rows);
  const decided = status?.actionUndone || busy;

  return (
    <details
      className="action-resolution save-batch"
      onToggle={(event) => {
        if (event.target === event.currentTarget) setOpen(event.currentTarget.open);
      }}
    >
      <summary>Resolver salvaguardas em lote · {message.targetActorIds?.length ?? 0} alvos</summary>
      {open && (
        <fieldset disabled={decided}>
          {!status && <p role="status">Consultando alvos registrados…</p>}
          {status && (
            <>
              <p>
                <strong>
                  {ABILITY_LABELS[status.save.ability]} · CD {status.save.dc}
                </strong>{" "}
                · sucesso: {status.save.effect === "half" ? "metade do dano" : "nenhum dano"};
                falha: dano integral antes das defesas.
              </p>
              <ul className="save-batch__rows" aria-label="Salvaguardas por alvo">
                {rows.map((row) => (
                  <li key={row.actorId}>
                    <strong>{row.name}</strong>{" "}
                    <small>
                      ·{" "}
                      {row.type === "character" && row.ownerUserId !== null ? "Personagem" : "NPC"}
                    </small>
                    {!row.save ? (
                      <p>
                        Salvaguarda pendente
                        {row.type === "character" && row.ownerUserId !== null
                          ? " · aguarde o dono da ficha"
                          : " · mestre pode rolar"}
                        .
                      </p>
                    ) : (
                      <>
                        <p>
                          {row.save.success ? "Sucesso" : "Falha"}
                          {row.save.manual
                            ? " por decisão da mesa"
                            : ` · ${row.save.roll?.total ?? "—"}`}
                          {row.save.mode ? ` · ${row.save.mode}` : ""} · {row.preview.damage} PV
                          calculados.
                        </p>
                        {row.save.roll && (
                          <small>
                            {row.save.roll.formula} · {row.save.roll.detail}
                            {row.save.roll.id ? ` · roll ${row.save.roll.id.slice(0, 8)}` : ""}
                          </small>
                        )}
                        {row.save.reason && <small>Decisão: {row.save.reason}</small>}
                        <small>
                          Registrado por {row.save.userName ?? "Usuário"}
                          {row.save.createdAt
                            ? ` · ${new Date(row.save.createdAt).toLocaleString("pt-BR")}`
                            : ""}
                        </small>
                        {row.save.history?.map((old, index) => (
                          <small key={index}>
                            Decisão anterior: {old.success ? "sucesso" : "falha"} ·{" "}
                            {old.reason ?? old.roll?.detail ?? "rolagem original"} ·{" "}
                            {old.userName ?? "Usuário"}
                          </small>
                        ))}
                        {row.preview.steps.map((step, index) => (
                          <small key={index}>{step}</small>
                        ))}
                      </>
                    )}
                    {row.application && (
                      <p>
                        {row.application.undone
                          ? "Aplicação desfeita"
                          : `Dano confirmado: ${row.application.resolution.damage} PV`}
                        .
                      </p>
                    )}
                    {row.save && !row.application && (
                      <button
                        type="button"
                        onClick={() => {
                          setDecisionFor(decisionFor === row.actorId ? null : row.actorId);
                          setReason("");
                        }}
                      >
                        Revisar decisão de {row.name}
                      </button>
                    )}
                    {decisionFor === row.actorId && row.save && !row.application && (
                      <div>
                        <label>
                          Motivo da revisão
                          <input
                            value={reason}
                            maxLength={240}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Ex.: resistência lendária"
                          />
                        </label>
                        <button
                          type="button"
                          disabled={!reason.trim()}
                          onClick={() =>
                            void submit(
                              "save-batch",
                              {
                                targets: [
                                  {
                                    actorId: row.actorId,
                                    decision: "success",
                                    reason: reason.trim(),
                                  },
                                ],
                              },
                              `Sucesso de ${row.name} registrado.`,
                            )
                          }
                        >
                          Decidir sucesso
                        </button>
                        <button
                          type="button"
                          disabled={!reason.trim()}
                          onClick={() =>
                            void submit(
                              "save-batch",
                              {
                                targets: [
                                  {
                                    actorId: row.actorId,
                                    decision: "failure",
                                    reason: reason.trim(),
                                  },
                                ],
                              },
                              `Falha de ${row.name} registrada.`,
                            )
                          }
                        >
                          Decidir falha
                        </button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
              {!!npcs.length && (
                <section>
                  <strong>Rolagem de NPCs pendentes ({npcs.length})</strong>
                  <div className="resolution-fields">
                    <label>
                      Modo
                      <select value={mode} onChange={(e) => setMode(e.target.value)}>
                        <option value="normal">Normal</option>
                        <option value="advantage">Vantagem</option>
                        <option value="disadvantage">Desvantagem</option>
                      </select>
                    </label>
                    <label>
                      Bônus situacional
                      <input
                        type="number"
                        min={-30}
                        max={30}
                        value={bonus}
                        onChange={(e) => setBonus(Number(e.target.value))}
                      />
                    </label>
                  </div>
                  <button
                    type="button"
                    onClick={() =>
                      void submit(
                        "save-batch",
                        { targets: npcs.map((actorId) => ({ actorId, mode, bonus })) },
                        `${npcs.length} salvaguardas registradas.`,
                      )
                    }
                  >
                    Rolar NPCs em lote
                  </button>
                </section>
              )}
              {!!unresolved.length && (
                <p role="status">Aguardando salvaguardas: {unresolved.join(", ")}.</p>
              )}
              {!!ready.length && (
                <button
                  type="button"
                  disabled={!!unresolved.length}
                  onClick={() =>
                    void submit(
                      "resolve-batch",
                      { actorIds: ready },
                      `${ready.length} aplicações confirmadas.`,
                    )
                  }
                >
                  Confirmar aplicação em lote ({ready.length})
                </button>
              )}
              {!!note && <small role="status">{note}</small>}
              {!!status.operations?.length && (
                <details>
                  <summary>Histórico das operações agrupadas ({status.operations.length})</summary>
                  {status.operations.map((operation) => (
                    <small key={operation.requestId}>
                      {operation.kind === "save" ? "Salvaguardas" : "Aplicação"} ·{" "}
                      {operation.actorIds.length} alvos · {operation.userName} ·{" "}
                      {new Date(operation.createdAt).toLocaleString("pt-BR")} ·{" "}
                      {operation.requestId.slice(0, 8)}
                    </small>
                  ))}
                </details>
              )}
              <small>
                O mestre pode ajustar cada alvo individualmente em «Resolver alvos» antes da
                confirmação em lote. O histórico completo permanece no servidor.
              </small>
            </>
          )}
        </fieldset>
      )}
    </details>
  );
}
