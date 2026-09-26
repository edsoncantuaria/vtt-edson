import { useState } from "react";
import type { Actor, Token } from "@vtt/core";
import {
  availableSpellSlots,
  componentSummary,
  missileAllocations,
  missileCount,
  type SpellOption,
  type SpellCastChoice,
} from "../../lib/spellcasting";
import { Modal } from "../Modal";

function splitMissiles(ids: string[], expected: number): Record<string, number> {
  const result: Record<string, number> = {};
  ids.forEach((id, index) => {
    result[id] = index === 0 ? Math.max(1, expected - ids.length + 1) : 1;
  });
  return result;
}

export function SpellCastDialog({
  actor,
  spell,
  tokens,
  selectedTokenIds,
  busy,
  onClose,
  onCast,
}: {
  actor: Actor;
  spell: SpellOption;
  tokens: Token[];
  selectedTokenIds: string[];
  busy: boolean;
  onClose: () => void;
  onCast: (choice: SpellCastChoice, tokenIds: string[]) => Promise<void>;
}) {
  const selectedTokens = selectedTokenIds;
  const slots = availableSpellSlots(actor, spell);
  const [ritual, setRitual] = useState(!spell.canCast && spell.canRitual);
  const [slot, setSlot] = useState(slots[0] ?? spell.level);
  const [selected, setSelected] = useState<string[]>(
    selectedTokens.filter((id) => tokens.some((token) => token.id === id)),
  );
  const [single, setSingle] = useState(
    selectedTokens.find((id) => tokens.some((token) => token.id === id)) ?? "",
  );
  const [center, setCenter] = useState(
    selectedTokens.find((id) => tokens.some((token) => token.id === id)) ?? "",
  );
  const [counts, setCounts] = useState<Record<string, number>>(() =>
    splitMissiles(selected, missileCount(spell, slot)),
  );
  const [materialConfirmed, setMaterialConfirmed] = useState(false);
  const expected = missileCount(spell, slot);
  const allocation = missileAllocations(selected, counts, expected);
  const genericSingle = spell.kind === "catalog-action" && spell.target === "single";
  const genericMultiple = spell.kind === "catalog-action" && spell.target === "multiple";
  const canSubmit =
    (!spell.components.m || materialConfirmed) &&
    (ritual ? spell.canRitual : spell.canCast && (spell.level === 0 || slots.includes(slot))) &&
    (spell.kind === "missiles"
      ? allocation !== null
      : spell.kind === "area-save"
        ? !!center
        : spell.kind === "heal" || genericSingle
          ? !!single
          : genericMultiple
            ? selected.length >= 1 && selected.length <= (spell.maxTargets ?? 50)
            : true);

  function changeTargets(next: string[]) {
    setSelected(next);
    setCounts(splitMissiles(next, expected));
  }

  async function confirm() {
    if (!canSubmit || busy) return;
    const choice: SpellCastChoice = {
      ...(spell.level > 0 && !ritual ? { slotLevel: slot } : {}),
      ...(ritual ? { ritual: true } : {}),
      ...(spell.components.m ? { componentsConfirmed: materialConfirmed } : {}),
      ...(spell.kind === "missiles" && allocation ? { missiles: allocation } : {}),
      ...(spell.kind === "area-save" ? { centerTokenId: center } : {}),
    };
    const tokenIds =
      spell.kind === "missiles" || genericMultiple
        ? selected
        : spell.kind === "heal" || genericSingle
          ? [single]
          : [];
    try {
      await onCast(choice, tokenIds);
      onClose();
    } catch {
      /* The sheet shows the server error; keep choices and retry key intact. */
    }
  }

  return (
    <Modal
      title={`Conjurar ${spell.name}`}
      onClose={() => {
        if (!busy) onClose();
      }}
    >
      <section className="sheet-items" aria-label="Escolhas da conjuração">
        <p>
          <strong>{spell.name}</strong> · nível {spell.level} · {spell.edition} · {spell.source}
        </p>
        <small>
          Componentes: {componentSummary(spell)} · alcance {spell.rangeFeet} pés
          {spell.concentration ? " · exige concentração" : ""}
        </small>
        {!!spell.components.m && (
          <label>
            <input
              type="checkbox"
              checked={materialConfirmed}
              disabled={busy}
              onChange={(event) => setMaterialConfirmed(event.target.checked)}
            />
            Confirmo que disponho dos componentes materiais indicados pela magia.
          </label>
        )}
        {spell.requiresPreparation && !spell.prepared && !spell.canRitual && (
          <p role="status">Prepare esta magia na ficha antes de conjurá-la.</p>
        )}
        {spell.ritual && (
          <label>
            <input
              type="checkbox"
              checked={ritual}
              disabled={busy || !spell.canRitual}
              onChange={(event) => setRitual(event.target.checked)}
            />{" "}
            Conjurar como ritual (+10 minutos, sem gastar espaço)
          </label>
        )}
        {spell.level > 0 && !ritual && (
          <label>
            Espaço de magia
            <select
              value={slot}
              disabled={busy || !slots.length}
              onChange={(event) => {
                const next = Number(event.target.value);
                setSlot(next);
                setCounts(splitMissiles(selected, missileCount(spell, next)));
              }}
            >
              {!slots.length && <option value={spell.level}>Sem espaços disponíveis</option>}
              {slots.map((level) => (
                <option key={level} value={level}>
                  Nível {level} ·{" "}
                  {actor.system.spells.slots[String(level)].max -
                    actor.system.spells.slots[String(level)].used}{" "}
                  livre(s)
                </option>
              ))}
            </select>
          </label>
        )}
        {spell.kind === "missiles" && (
          <fieldset>
            <legend>Distribuir {expected} mísseis entre os tokens visíveis</legend>
            <small>
              Selecione os alvos e atribua ao menos 1 míssil a cada um. Cada dardo terá rolagem
              rastreável.
            </small>
            {tokens.map((token) => (
              <label key={token.id}>
                <input
                  type="checkbox"
                  disabled={busy}
                  checked={selected.includes(token.id)}
                  onChange={(event) =>
                    changeTargets(
                      event.target.checked
                        ? [...selected, token.id]
                        : selected.filter((id) => id !== token.id),
                    )
                  }
                />
                {token.name}
                {selected.includes(token.id) && (
                  <input
                    type="number"
                    min={1}
                    max={expected}
                    aria-label={`Mísseis em ${token.name}`}
                    value={counts[token.id] ?? 0}
                    onChange={(event) =>
                      setCounts((current) => ({
                        ...current,
                        [token.id]: Number(event.target.value),
                      }))
                    }
                  />
                )}
              </label>
            ))}
            <small role="status">
              Distribuídos: {selected.reduce((sum, id) => sum + (counts[id] || 0), 0)}/{expected}
            </small>
          </fieldset>
        )}
        {spell.kind === "area-save" && (
          <label>
            Centro da área · raio de {spell.areaFeet} pés
            <select
              value={center}
              disabled={busy}
              onChange={(event) => setCenter(event.target.value)}
            >
              <option value="">Escolha um token visível como centro</option>
              {tokens.map((token) => (
                <option key={token.id} value={token.id}>
                  {token.name}
                </option>
              ))}
            </select>
            <small>
              Todos os tokens no raio, inclusive aliados e conjurador, entram na salvaguarda. O
              servidor mede a distância.
            </small>
          </label>
        )}
        {spell.kind === "heal" && (
          <label>
            Aliado ou criatura a curar
            <select
              value={single}
              disabled={busy}
              onChange={(event) => setSingle(event.target.value)}
            >
              <option value="">Selecione um token ao alcance</option>
              {tokens.map((token) => (
                <option key={token.id} value={token.id}>
                  {token.name}
                </option>
              ))}
            </select>
          </label>
        )}
        {genericSingle && (
          <label>
            Alvo da magia
            <select
              value={single}
              disabled={busy}
              onChange={(event) => setSingle(event.target.value)}
            >
              <option value="">Selecione um token ao alcance</option>
              {tokens.map((token) => (
                <option key={token.id} value={token.id}>
                  {token.name}
                </option>
              ))}
            </select>
          </label>
        )}
        {genericMultiple && (
          <fieldset>
            <legend>Escolha até {spell.maxTargets ?? 50} alvos</legend>
            {tokens.map((token) => (
              <label key={token.id}>
                <input
                  type="checkbox"
                  checked={selected.includes(token.id)}
                  disabled={busy}
                  onChange={(event) =>
                    setSelected((current) =>
                      event.target.checked
                        ? [...current, token.id]
                        : current.filter((id) => id !== token.id),
                    )
                  }
                />
                {token.name}
              </label>
            ))}
          </fieldset>
        )}
        {spell.kind === "catalog-action" && (
          <p>Os efeitos e a concentração são definidos pelo catálogo da campanha.</p>
        )}
        <p className="panel-hint">
          Cancelar não consome espaços nem cria rolagens; confirmar usa o executor da mesa.
        </p>
        <footer>
          <button type="button" disabled={busy} onClick={onClose}>
            Cancelar
          </button>
          <button
            type="button"
            className="primary"
            disabled={busy || !canSubmit}
            onClick={() => void confirm()}
          >
            Confirmar conjuração
          </button>
        </footer>
      </section>
    </Modal>
  );
}
