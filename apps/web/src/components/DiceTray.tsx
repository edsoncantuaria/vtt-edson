import { useState } from "react";
import { isValidDiceFormula } from "@vtt/core";
import { rollToChat } from "../lib/roll";
import { useSession } from "../store/session";
import { Icon } from "./Icon";
export function DiceTray() {
  const { sceneId, setError } = useSession();
  const [die, setDie] = useState(20);
  const [count, setCount] = useState(1);
  const [modifier, setModifier] = useState(0);
  const [mode, setMode] = useState("normal");
  const [busy, setBusy] = useState(false);
  const formula =
    (die === 20 && mode !== "normal"
      ? "2d20" + (mode === "advantage" ? "kh1" : "kl1")
      : count + "d" + die) + (modifier ? (modifier > 0 ? "+" : "") + modifier : "");
  async function roll() {
    if (!sceneId || busy || !isValidDiceFormula(formula)) return;
    setBusy(true);
    try {
      await rollToChat(sceneId, formula);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível rolar.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="dice-tray">
      <div className="dice-tray__label">
        <Icon name="dice" />
        <span>
          Dados da mesa<small>Resultados visíveis para todos</small>
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
        <button
          className="primary"
          disabled={busy || !isValidDiceFormula(formula)}
          onClick={() => void roll()}
        >
          <Icon name="dice" size={18} />
          {busy ? "Rolando…" : formula}
        </button>
      </div>
    </div>
  );
}
