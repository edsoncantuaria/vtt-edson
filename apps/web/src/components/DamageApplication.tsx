import { useEffect, useRef, useState } from "react";
import { ABILITY_LABELS, type Actor, type ChatMessage } from "@vtt/core";
import { api } from "../lib/api";
import { isManagerRole, useSession } from "../store/session";
import { resolutionTargets } from "../lib/resolutionTargets";

type SaveResult = {
  success: boolean;
  dc: number;
  roll?: { total: number; detail: string; formula: string };
  reason?: string;
  houseRules?: string[];
};
type AttackResult = {
  ac: number;
  total: number;
  critical: boolean;
  fumble: boolean;
  hit: boolean;
  automaticHit?: boolean;
};
type Resolution = {
  damage: number;
  steps: string[];
  attack?: AttackResult | null;
  concentrationDc?: number;
  concentrationSave?: SaveResult;
  createdAt?: string;
  reason?: string;
  hitDecision?: "auto" | "hit" | "miss";
  damageOverride?: number | null;
  manual?: boolean;
  corrections?: Array<{
    requestId: string;
    previousDamage: number;
    damage: number;
    reason: string;
    userId: number;
    createdAt: string;
  }>;
};
type TargetResult = {
  preview: (Resolution & { hit: boolean | null; pendingSave: boolean }) | null;
  save: SaveResult | null;
  application: {
    undone: boolean;
    before: { value: number; temp?: number };
    after: { value: number; temp?: number };
    resolution: Resolution;
  } | null;
  actionUndone: boolean;
};

function SaveOutcome({ result }: { result: SaveResult }) {
  return (
    <div className="resolution-result">
      <strong>
        {result.success ? "Sucesso" : "Falha"}
        {result.roll ? `: ${result.roll.total}` : " por decisão da mesa"} · CD {result.dc}
      </strong>
      {result.roll && (
        <small>
          {result.roll.formula} · {result.roll.detail}
        </small>
      )}
      {result.reason && <small>{result.reason}</small>}
      {!!result.houseRules?.length && <small>Regras da mesa: {result.houseRules.join(", ")}</small>}
    </div>
  );
}

export function DamageApplication({ message }: { message: ChatMessage }) {
  const { actors, role, user, sceneId, upsertActor, setError, targetActorIds } = useSession();
  const lastChatId = useSession((session) => session.state.chat.at(-1)?.id);
  const [target, setTarget] = useState("");
  const [factor, setFactor] = useState("auto");
  const [hitDecision, setHitDecision] = useState<"auto" | "hit" | "miss">("auto");
  const [damageOverride, setDamageOverride] = useState("");
  const [correctionAmount, setCorrectionAmount] = useState("");
  const [correctionDecision, setCorrectionDecision] = useState<"" | "auto" | "hit" | "miss">("");
  const [correctionReason, setCorrectionReason] = useState("");
  const pendingCorrection = useRef<{ key: string; id: string } | null>(null);
  const [mode, setMode] = useState("normal");
  const [bonus, setBonus] = useState(0);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<TargetResult | null>(null);
  const [status, setStatus] = useState("");
  const [opened, setOpened] = useState(false);
  const [targetConfirmed, setTargetConfirmed] = useState(false);
  const { editable, fixedTargets, mapTargets } = resolutionTargets(
    actors,
    role,
    user?.id,
    message,
    targetActorIds,
  );
  const selectedFromAction = fixedTargets.includes(Number(target));
  const confirmed = selectedFromAction || targetConfirmed;
  const correcting = hitDecision !== "auto" || factor !== "auto" || damageOverride !== "";
  const selectedTarget = editable.find((a) => String(a.id) === target);
  const path = `/scenes/${sceneId}/damage/${message.id}`;

  useEffect(() => {
    if (!target || !opened) return;
    let active = true;
    setLoading(true);
    setResult(null);
    api<TargetResult>(`${path}?actorId=${target}`)
      .then((value) => {
        if (active) setResult(value);
      })
      .catch((error) => {
        if (active)
          setError(error instanceof Error ? error.message : "Não foi possível consultar o alvo.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [target, opened, path, setError, lastChatId]);

  useEffect(() => {
    if (!opened || target || !mapTargets.length) return;
    setTarget(String(mapTargets[0].id));
    setTargetConfirmed(false);
  }, [opened, target, mapTargets]);

  async function execute(suffix: string, data: object, success: string) {
    if (!target || busy || loading) return;
    setBusy(true);
    try {
      const response = await api<{ actor?: Actor }>(path + suffix, {
        method: "POST",
        body: JSON.stringify({ actorId: Number(target), ...data }),
      });
      if (response.actor) upsertActor(response.actor);
      setResult(await api<TargetResult>(`${path}?actorId=${target}`));
      setStatus(success);
      return true;
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível resolver a ação.");
      return false;
    } finally {
      setBusy(false);
    }
  }
  async function correctApplied() {
    if (
      !applied ||
      applied.undone ||
      !isManagerRole(role) ||
      correctionAmount === "" ||
      !correctionReason.trim()
    )
      return;
    const decision = correctionDecision || applied.resolution.hitDecision || "auto";
    const key = `${sceneId}:${message.id}:${target}:${correctionAmount}:${decision}:${correctionReason.trim()}`;
    if (pendingCorrection.current?.key !== key)
      pendingCorrection.current = { key, id: crypto.randomUUID() };
    if (
      await execute(
        "",
        {
          correct: true,
          requestId: pendingCorrection.current.id,
          damageOverride: Number(correctionAmount),
          hitDecision: decision,
          reason: correctionReason.trim(),
        },
        "Correção registrada sem refazer a rolagem.",
      )
    ) {
      pendingCorrection.current = null;
      setCorrectionAmount("");
      setCorrectionReason("");
      setCorrectionDecision("");
    }
  }
  const applied = result?.application;
  const disabled = busy || loading || !result || result.actionUndone || !confirmed;
  const concentration = applied?.resolution;
  const saveControls = (
    <div className="resolution-fields">
      <label>
        Rolagem
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
  );

  return (
    <details className="action-resolution" onToggle={(e) => setOpened(e.currentTarget.open)}>
      <summary>
        Resolver alvos
        {message.save
          ? ` · ${ABILITY_LABELS[message.save.ability]}, CD ${message.save.dc}`
          : " e dano"}
      </summary>
      <fieldset disabled={busy}>
        <label>
          Alvo · dono da ficha ou mestre
          <select
            value={target}
            onChange={(e) => {
              setTarget(e.target.value);
              setResult(null);
              setStatus("");
              setFactor("auto");
              setTargetConfirmed(false);
            }}
          >
            <option value="">Selecione uma ficha</option>
            {editable.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name}
              </option>
            ))}
          </select>
        </label>
        {!!mapTargets.length && (
          <div className="resolution-fields">
            <span>{fixedTargets.length ? "Alvos desta ação:" : "Alvos escolhidos no mapa:"}</span>
            {mapTargets.map((actor) => (
              <button
                type="button"
                key={actor.id}
                aria-pressed={String(actor.id) === target}
                onClick={() => {
                  setTarget(String(actor.id));
                  setResult(null);
                  setStatus("");
                  setFactor("auto");
                  setTargetConfirmed(false);
                }}
              >
                {actor.name}
              </button>
            ))}
          </div>
        )}
        {selectedTarget && !selectedFromAction && (
          <label>
            <input
              type="checkbox"
              checked={targetConfirmed}
              onChange={(e) => setTargetConfirmed(e.target.checked)}
            />{" "}
            Confirmo {selectedTarget.name} como alvo desta resolução.
          </label>
        )}
        {!editable.length && <p>O mestre resolve os alvos que você não controla.</p>}
        {loading && <p role="status">Consultando alvo…</p>}
        {result?.actionUndone && <p>Ação desfeita. Execute uma nova ação para jogar novamente.</p>}
        {message.save && result && (
          <section>
            {result.save ? (
              <>
                <SaveOutcome result={result.save} />
                {isManagerRole(role) && !applied && (
                  <details>
                    <summary>Substituir resultado por decisão do mestre</summary>
                    <label>
                      Motivo
                      <input
                        value={reason}
                        maxLength={240}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Ex.: Resistência Lendária"
                      />
                    </label>
                    <button
                      disabled={disabled || !reason.trim()}
                      onClick={() =>
                        void execute(
                          "/save",
                          { decision: "success", reason },
                          "Decisão registrada no histórico.",
                        )
                      }
                    >
                      Decidir sucesso
                    </button>
                    <button
                      disabled={disabled || !reason.trim()}
                      onClick={() =>
                        void execute(
                          "/save",
                          { decision: "failure", reason },
                          "Decisão registrada no histórico.",
                        )
                      }
                    >
                      Decidir falha
                    </button>
                  </details>
                )}
              </>
            ) : (
              <>
                <p>
                  Em caso de sucesso:{" "}
                  {message.save.effect === "half" ? "metade do dano" : "nenhum dano"}. Confirme as
                  condições e reações antes de rolar.
                </p>
                {saveControls}
                <button
                  disabled={disabled || !!applied}
                  onClick={() => void execute("/save", { mode, bonus }, "Salvaguarda registrada.")}
                >
                  Rolar salvaguarda
                </button>
                <details>
                  <summary>Decisão da mesa</summary>
                  <label>
                    Motivo
                    <input
                      maxLength={240}
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder="Ex.: Resistência Lendária"
                    />
                  </label>
                  {isManagerRole(role) && (
                    <button
                      disabled={disabled || !!applied || !reason.trim()}
                      onClick={() =>
                        void execute(
                          "/save",
                          { decision: "success", reason },
                          "Sucesso registrado.",
                        )
                      }
                    >
                      Registrar sucesso
                    </button>
                  )}
                  <button
                    disabled={disabled || !!applied || !reason.trim()}
                    onClick={() =>
                      void execute("/save", { decision: "failure", reason }, "Falha registrada.")
                    }
                  >
                    Registrar falha voluntária
                  </button>
                  <small>Em 2014, a falha voluntária requer decisão do mestre.</small>
                </details>
              </>
            )}
          </section>
        )}
        {result?.preview && !applied && (
          <section>
            <strong>
              {message.rolls?.some((roll) => roll.kind === "damage")
                ? "Dano calculado"
                : "Efeito da ação"}
              : {result.preview.damage} PV de dano
            </strong>
            {result.preview.attack && (
              <div className="resolution-result" role="status">
                <strong>
                  {result.preview.attack.hit ? "Acerto" : "Falha"}: {result.preview.attack.total}{" "}
                  contra CA {result.preview.attack.ac}
                </strong>
                <small>
                  Resultado do ataque para o alvo autorizado · calculado pelo servidor
                  {result.preview.attack.critical
                    ? " · crítico"
                    : result.preview.attack.fumble
                      ? " · 1 natural"
                      : ""}
                </small>
              </div>
            )}
            {result.preview.steps.map((step, index) => (
              <small key={index}>{step}</small>
            ))}
            {result.preview.pendingSave && (
              <p>Aguardando salvaguarda para calcular o dano final.</p>
            )}
            <label>
              Aplicação
              <select
                value={factor}
                disabled={!isManagerRole(role)}
                onChange={(e) => {
                  setFactor(e.target.value);
                  setDamageOverride("");
                }}
              >
                <option value="auto">Usar regras da ficha</option>
                <option value="1">Manual: dano rolado integral</option>
                <option value="0.5">Manual: metade do dano rolado</option>
                <option value="2">Manual: dobro do dano rolado</option>
                <option value="0">Manual: nenhum dano</option>
              </select>
            </label>
            {isManagerRole(role) && result.preview.attack && (
              <label>
                Decisão sobre o acerto
                <select
                  value={hitDecision}
                  onChange={(e) => setHitDecision(e.target.value as "auto" | "hit" | "miss")}
                >
                  <option value="auto">Usar acerto calculado pelo servidor</option>
                  <option value="hit">Registrar acerto por decisão do mestre</option>
                  <option value="miss">Registrar erro por decisão do mestre</option>
                </select>
              </label>
            )}
            {isManagerRole(role) && message.rolls?.some((roll) => roll.kind === "damage") && (
              <label>
                Dano exato corrigido (opcional)
                <input
                  type="number"
                  min={0}
                  max={100000}
                  value={damageOverride}
                  onChange={(e) => {
                    setDamageOverride(e.target.value);
                    if (e.target.value !== "") setFactor("auto");
                  }}
                  placeholder="Ex.: 7, ou 0 para ignorar dano"
                />
              </label>
            )}
            {correcting && (
              <label>
                Motivo da decisão
                <input
                  required
                  maxLength={240}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Cobertura, resistência ou decisão da mesa"
                />
              </label>
            )}
            <small>
              Revise cobertura, reações e defesas condicionais. A opção manual substitui todos os
              multiplicadores.
            </small>
            <button
              disabled={
                disabled ||
                (factor === "auto" && damageOverride === "" && result.preview.pendingSave) ||
                (correcting && !reason.trim()) ||
                (hitDecision === "miss" && damageOverride !== "" && Number(damageOverride) > 0)
              }
              onClick={() =>
                void execute(
                  "",
                  correcting
                    ? {
                        ...(factor !== "auto" ? { factor } : {}),
                        ...(damageOverride !== ""
                          ? { damageOverride: Number(damageOverride) }
                          : {}),
                        ...(hitDecision !== "auto" ? { hitDecision } : {}),
                        reason: reason.trim(),
                      }
                    : {},
                  `Resolução confirmada para ${selectedTarget?.name ?? "alvo"}.`,
                )
              }
            >
              Confirmar {message.rolls?.some((roll) => roll.kind === "damage") ? "dano" : "efeito"}{" "}
              em {selectedTarget?.name ?? "alvo"}
            </button>
          </section>
        )}
        {applied && (
          <section>
            <strong>
              {applied.undone
                ? "Dano desfeito"
                : `Dano aplicado: ${applied.resolution.damage ?? "—"}`}
            </strong>
            <small>
              PV: {applied.before.value} → {applied.after.value} · Temporários:{" "}
              {applied.before.temp ?? 0} → {applied.after.temp ?? 0}
            </small>
            {applied.resolution.steps?.map((step, index) => (
              <small key={index}>{step}</small>
            ))}
            {applied.resolution.reason && <small>Decisão: {applied.resolution.reason}</small>}
            {applied.resolution.corrections?.map((correction) => (
              <small key={correction.requestId}>
                Correção auditada: {correction.previousDamage} → {correction.damage} PV ·{" "}
                {correction.reason}
              </small>
            ))}
            {applied.resolution.attack && (
              <small>
                Acerto: {applied.resolution.attack.hit ? "sim" : "não"} · rolagem original{" "}
                {applied.resolution.attack.total}
                {applied.resolution.hitDecision !== "auto" ? " · decisão manual do mestre" : ""}
              </small>
            )}
            {!applied.undone && (
              <>
                {isManagerRole(role) && message.rolls?.some((roll) => roll.kind === "damage") && (
                  <details>
                    <summary>Corrigir dano já confirmado</summary>
                    <small>
                      A correção parte dos PV anteriores à ação e preserva as rolagens. Efeitos,
                      salvaguardas e concentração exigem revisão separada.
                    </small>
                    <label>
                      Novo dano exato
                      <input
                        type="number"
                        min={0}
                        max={100000}
                        value={correctionAmount}
                        onChange={(e) => setCorrectionAmount(e.target.value)}
                        placeholder="0 para ignorar o dano"
                      />
                    </label>
                    {applied.resolution.attack && (
                      <label>
                        Decisão sobre o acerto
                        <select
                          value={correctionDecision || applied.resolution.hitDecision || "auto"}
                          onChange={(e) =>
                            setCorrectionDecision(e.target.value as "auto" | "hit" | "miss")
                          }
                        >
                          <option value="auto">Acerto calculado originalmente</option>
                          <option value="hit">Acerto decidido pelo mestre</option>
                          <option value="miss">Erro decidido pelo mestre</option>
                        </select>
                      </label>
                    )}
                    <label>
                      Motivo obrigatório
                      <input
                        value={correctionReason}
                        maxLength={240}
                        onChange={(e) => setCorrectionReason(e.target.value)}
                        placeholder="Revisão da decisão ou do dano"
                      />
                    </label>
                    <button
                      type="button"
                      disabled={
                        disabled ||
                        correctionAmount === "" ||
                        !Number.isInteger(Number(correctionAmount)) ||
                        Number(correctionAmount) < 0 ||
                        Number(correctionAmount) > 100000 ||
                        !correctionReason.trim() ||
                        ((correctionDecision || applied.resolution.hitDecision) === "miss" &&
                          Number(correctionAmount) > 0)
                      }
                      onClick={() => void correctApplied()}
                    >
                      Confirmar correção auditada
                    </button>
                  </details>
                )}
                {concentration?.concentrationSave ? (
                  <SaveOutcome result={concentration.concentrationSave} />
                ) : (
                  concentration?.concentrationDc && (
                    <>
                      <p>
                        Concentração pendente · Constituição, CD {concentration.concentrationDc}
                      </p>
                      {saveControls}
                      <button
                        disabled={disabled}
                        onClick={() =>
                          void execute("/concentration", { mode, bonus }, "Concentração resolvida.")
                        }
                      >
                        Testar concentração
                      </button>
                    </>
                  )
                )}
                <button
                  disabled={busy}
                  onClick={() => void execute("", { undo: true }, "Dano desfeito.")}
                >
                  Desfazer dano e concentração
                </button>
              </>
            )}
          </section>
        )}
      </fieldset>
      <small role="status">{status}</small>
    </details>
  );
}
