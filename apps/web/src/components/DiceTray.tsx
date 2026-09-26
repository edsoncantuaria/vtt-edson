import { useEffect, useRef, useState } from "react";
import { isValidDiceFormula, RollRecordSchema } from "@vtt/core";
import { rollHistoryPage, rollToChat } from "../lib/roll";
import type { RollRecord } from "@vtt/core";
import { useSession } from "../store/session";
import { Icon } from "./Icon";
export function DiceTray() {
  const { sceneId, user, setError } = useSession();
  const [die, setDie] = useState(20);
  const [count, setCount] = useState(1);
  const [modifier, setModifier] = useState(0);
  const [mode, setMode] = useState("normal");
  const [busy, setBusy] = useState(false);
  const [visibility, setVisibility] = useState<"public" | "gm">("public");
  const [history, setHistory] = useState<RollRecord[] | null>(null);
  const [historyBusy, setHistoryBusy] = useState(false);
  const [historyPage, setHistoryPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [lastRoll, setLastRoll] = useState<RollRecord | null>(null);
  const pendingRoll = useRef<{ key: string; id: string } | null>(null);
  useEffect(() => {
    setHistory(null);
    setHistoryPage(1);
    setHasMore(false);
    setLastRoll(null);
    pendingRoll.current = null;
  }, [sceneId, user?.id]);
  const formula =
    (die === 20 && mode !== "normal" ? "d20" : count + "d" + die) +
    (modifier ? (modifier > 0 ? "+" : "") + modifier : "");
  async function roll() {
    if (!sceneId || busy || !isValidDiceFormula(formula)) return;
    const key = `${sceneId}:${formula}:${mode}:${visibility}`;
    if (pendingRoll.current?.key !== key) pendingRoll.current = { key, id: crypto.randomUUID() };
    setBusy(true);
    try {
      const result = await rollToChat(sceneId, formula, "Dados da mesa", {
        requestId: pendingRoll.current.id,
        mode: die === 20 ? (mode as RollRecord["mode"]) : "normal",
        visibility,
      });
      if (!result.roll) throw new Error("O servidor não devolveu o resultado da rolagem.");
      if (useSession.getState().sceneId !== sceneId || useSession.getState().user?.id !== user?.id)
        return;
      setLastRoll(RollRecordSchema.parse(result.roll));
      pendingRoll.current = null;
      if (history) await loadHistory(historyPage);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível rolar.");
    } finally {
      setBusy(false);
    }
  }
  async function loadHistory(page = 1) {
    if (!sceneId || historyBusy) return;
    setHistoryBusy(true);
    try {
      const result = await rollHistoryPage(sceneId, page);
      if (useSession.getState().sceneId !== sceneId || useSession.getState().user?.id !== user?.id)
        return;
      setHistory(result.rolls);
      setHistoryPage(result.page);
      setHasMore(result.hasMore);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível consultar as rolagens.");
    } finally {
      setHistoryBusy(false);
    }
  }
  return (
    <div className="dice-tray">
      <div className="dice-tray__label">
        <Icon name="dice" />
        <span>
          Dados da mesa<small>Resultados calculados e guardados pelo servidor</small>
        </span>
      </div>
      <div className="dice-tray__dice">
        {[4, 6, 8, 10, 12, 20, 100].map((d) => (
          <button key={d} aria-pressed={die === d} onClick={() => setDie(d)}>
            d{d}
          </button>
        ))}
      </div>
      <div className="dice-tray__options">
        <label>
          Quantidade
          <input
            type="number"
            min={1}
            max={100}
            value={count}
            disabled={die === 20 && mode !== "normal"}
            onChange={(e) => setCount(Number(e.target.value))}
          />
        </label>
        <label>
          Modificador
          <input
            type="number"
            min={-100}
            max={100}
            value={modifier}
            onChange={(e) => setModifier(Number(e.target.value))}
          />
        </label>
        <label>
          Rolagem
          <select value={mode} disabled={die !== 20} onChange={(e) => setMode(e.target.value)}>
            <option value="normal">Normal</option>
            <option value="advantage">Vantagem</option>
            <option value="disadvantage">Desvantagem</option>
          </select>
        </label>
        <label>
          Visibilidade
          <select
            value={visibility}
            onChange={(e) => setVisibility(e.target.value as "public" | "gm")}
          >
            <option value="public">Pública · mesa inteira</option>
            <option value="gm">Reservada · você e o mestre</option>
          </select>
        </label>
        <button
          className="primary"
          disabled={busy || !isValidDiceFormula(formula)}
          onClick={() => void roll()}
        >
          <Icon name="dice" size={18} />
          {busy ? "Rolando…" : formula}
        </button>
      </div>
      {lastRoll && (
        <p role="status" className="panel-hint">
          {lastRoll.visibility === "gm" ? "Reservada" : "Pública"} · {lastRoll.formula} ={" "}
          <b>{lastRoll.total}</b> · ID {lastRoll.id.slice(0, 8)}
        </p>
      )}
      <button
        disabled={!sceneId || historyBusy}
        onClick={() => (history ? setHistory(null) : void loadHistory(1))}
      >
        {history ? "Ocultar histórico" : "Consultar histórico de rolagens"}
      </button>
      {history && (
        <ol aria-label="Histórico de rolagens" className="dice-history">
          {history
            .slice()
            .reverse()
            .map((record) => (
              <li key={record.id}>
                <span>
                  {record.visibility === "public"
                    ? "Pública"
                    : record.visibility === "gm"
                      ? "Reservada"
                      : "Privada"}{" "}
                  · {record.label ?? record.context}
                </span>
                <b>
                  {record.formula} = {record.total}
                </b>
                <small title={record.id}>{record.id.slice(0, 8)}</small>
              </li>
            ))}
        </ol>
      )}
      {history && (
        <div className="dice-history-pagination">
          <button
            disabled={historyBusy || historyPage === 1}
            onClick={() => void loadHistory(historyPage - 1)}
          >
            Mais recentes
          </button>
          <span>Página {historyPage}</span>
          <button
            disabled={historyBusy || !hasMore}
            onClick={() => void loadHistory(historyPage + 1)}
          >
            Mais antigas
          </button>
        </div>
      )}
    </div>
  );
}
