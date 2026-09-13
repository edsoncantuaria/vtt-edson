import { useEffect, useState } from "react";
import { ABILITY_LABELS, type Actor, type ChatMessage } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";

type SaveResult = {
  success: boolean;
  dc: number;
  roll?: { total: number; detail: string; formula: string };
  reason?: string;
  houseRules?: string[];
};
type AttackResult = { ac: number; total: number; critical: boolean; fumble: boolean; hit: boolean };
type Resolution = {
  damage: number;
  steps: string[];
  attack?: AttackResult | null;
  concentrationDc?: number;
  concentrationSave?: SaveResult;
  createdAt?: string;
  reason?: string;
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
  const [target, setTarget] = useState("");
  const [factor, setFactor] = useState("auto");
  const [mode, setMode] = useState("normal");
  const [bonus, setBonus] = useState(0);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<TargetResult | null>(null);
  const [status, setStatus] = useState("");
  const [opened, setOpened] = useState(false);
  const [targetConfirmed, setTargetConfirmed] = useState(false);
  const editable = actors.filter((a) => role === "gm" || a.ownerUserId === user?.id);
  const mapTargets = targetActorIds
    .map((id) => editable.find((actor) => actor.id === id))
    .filter((actor): actor is Actor => !!actor);
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
  }, [target, opened, path, setError]);

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
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível resolver a ação.");
    } finally {
      setBusy(false);
    }
  }
  const applied = result?.application;
  const disabled = busy || loading || !result || result.actionUndone || !targetConfirmed;
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
            <span>Alvos escolhidos no mapa:</span>
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
        {selectedTarget && (
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
                {role === "gm" && !applied && (
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
                  {role === "gm" && (
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
            <strong>Dano calculado: {result.preview.damage}</strong>
            {result.preview.attack && (
              <small>
                {result.preview.attack.hit ? "Acerto" : "Falha"}: {result.preview.attack.total}{" "}
                contra CA {result.preview.attack.ac}
                {result.preview.attack.critical
                  ? " · crítico"
                  : result.preview.attack.fumble
                    ? " · 1 natural"
                    : ""}
              </small>
            )}
            {result.preview.steps.map((step, index) => (
              <small key={index}>{step}</small>
            ))}
            {result.preview.pendingSave && (
              <p>Aguardando salvaguarda para calcular o dano final.</p>
            )}
            <label>
              Aplicação
              <select value={factor} onChange={(e) => setFactor(e.target.value)}>
                <option value="auto">Usar regras da ficha</option>
                <option value="1">Manual: dano rolado integral</option>
                <option value="0.5">Manual: metade do dano rolado</option>
                <option value="2">Manual: dobro do dano rolado</option>
                <option value="0">Manual: nenhum dano</option>
              </select>
            </label>
            {factor !== "auto" && (
              <label>
                Motivo da decisão
                <input maxLength={240} value={reason} onChange={(e) => setReason(e.target.value)} />
              </label>
            )}
            <small>
              Revise cobertura, reações e defesas condicionais. A opção manual substitui todos os
              multiplicadores.
            </small>
            <button
              disabled={disabled || (factor === "auto" && result.preview.pendingSave)}
              onClick={() =>
                void execute(
                  "",
                  factor === "auto" ? {} : { factor, reason },
                  `Dano aplicado a ${selectedTarget?.name ?? "alvo"}.`,
                )
              }
            >
              Confirmar dano em {selectedTarget?.name ?? "alvo"}
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
            {!applied.undone && (
              <>
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
