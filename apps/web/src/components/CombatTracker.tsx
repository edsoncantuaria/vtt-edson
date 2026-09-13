import { useRef, useState } from "react";
import type { Combat } from "@vtt/core";
import { api } from "../lib/api";
import { pluginRegistry } from "../lib/plugins";
import { isManagerRole, useSession } from "../store/session";
import { Icon } from "./Icon";
import { Modal } from "./Modal";
import "./CombatTracker.css";
export function CombatTracker() {
  const { sceneId, role, combat, actors, setCombat, setError, setPanel, setSelectedActorId } =
    useSession();
  const [actorId, setActorId] = useState("");
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const [ending, setEnding] = useState(false);
  async function action(path: string, data?: object, method = "POST") {
    if (!sceneId || pending.current) return;
    pending.current = true;
    setBusy(true);
    try {
      const res = await api<{ combat: Combat | null }>("/scenes/" + sceneId + "/combat" + path, {
        method,
        body: data ? JSON.stringify(data) : undefined,
      });
      if (useSession.getState().sceneId === sceneId) setCombat(res.combat);
      if (res.combat) {
        await pluginRegistry.hooks.emit("combat:turn", {
          sceneId,
          combatId: res.combat.id,
          round: res.combat.round,
          turn: res.combat.turn,
        });
      }
      setEnding(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível atualizar o combate.");
    } finally {
      pending.current = false;
      setBusy(false);
    }
  }
  const gm = isManagerRole(role);
  return (
    <div className="combat">
      {!combat ? (
        <div className="panel-empty">
          <Icon name="swords" size={38} />
          <h3>Antes de sacar as espadas…</h3>
          <p>
            {gm
              ? "Inicie um encontro, adicione os participantes e role a iniciativa. A ordem fica visível para toda a mesa."
              : "Quando o mestre iniciar um combate, você acompanha os turnos por aqui."}
          </p>
          {gm && (
            <button className="primary" disabled={busy} onClick={() => void action("/start")}>
              <Icon name="swords" size={17} />
              Iniciar combate
            </button>
          )}
        </div>
      ) : (
        <>
          <div className="combat-round">
            <span>
              RODADA<strong>{combat.round.toString().padStart(2, "0")}</strong>
            </span>
            <div>
              <small>AGORA É A VEZ DE</small>
              <h3>{combat.participants[combat.turn]?.name ?? "Preparar o encontro"}</h3>
              <p>
                {combat.participants.length}{" "}
                {combat.participants.length === 1 ? "combatente" : "combatentes"}
              </p>
            </div>
          </div>
          {gm && (
            <div className="turn-actions">
              <button
                className="icon-button"
                aria-label="Turno anterior"
                disabled={busy || !combat.participants.length}
                onClick={() => void action("/prev")}
              >
                <Icon name="back" size={18} />
              </button>
              <button
                className="primary"
                disabled={busy || !combat.participants.length}
                onClick={() => void action("/next")}
              >
                Próximo turno
                <Icon name="arrow" size={17} />
              </button>
            </div>
          )}
          <div className="combat-list-label">
            <span>INICIATIVA</span>
            <span>PARTICIPANTES</span>
          </div>
          {!combat.participants.length && (
            <p className="panel-hint">
              {gm
                ? "Adicione fichas abaixo para montar o encontro."
                : "O mestre está preparando os participantes."}
            </p>
          )}
          <ol className="initiative-list">
            {combat.participants.map((p, index) => {
              const actor = actors.find((a) => a.id === p.actorId);
              return (
                <li key={p.id} className={index === combat.turn ? "current" : ""}>
                  <strong>{p.initiative ?? "—"}</strong>
                  <button
                    className="combat-person"
                    disabled={!actor}
                    onClick={() => {
                      if (actor) {
                        setSelectedActorId(actor.id);
                        setPanel("actors");
                      }
                    }}
                  >
                    <span className="avatar">{p.name.slice(0, 2).toUpperCase()}</span>
                    <span>
                      {p.name}
                      <small>
                        {actor
                          ? actor.system.hp.value + "/" + actor.system.hp.max + " PV"
                          : "Combatente"}
                        {index === combat.turn ? " · Turno atual" : ""}
                      </small>
                    </span>
                  </button>
                  {gm && (
                    <button
                      className="icon-button danger"
                      title="Remover do combate"
                      aria-label={"Remover " + p.name + " do combate"}
                      disabled={busy}
                      onClick={() => void action("/combatants/" + p.id, undefined, "DELETE")}
                    >
                      <Icon name="close" size={14} />
                    </button>
                  )}
                </li>
              );
            })}
          </ol>
          {gm && (
            <>
              <form
                className="combat-add"
                onSubmit={(e) => {
                  e.preventDefault();
                  if (actorId) void action("/combatants", { actorId: Number(actorId) });
                }}
              >
                <label>
                  Adicionar ao encontro
                  <select value={actorId} onChange={(e) => setActorId(e.target.value)}>
                    <option value="">Escolher ficha…</option>
                    {actors.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.name}
                      </option>
                    ))}
                  </select>
                </label>
                <button disabled={busy || !actorId} aria-label="Adicionar combatente">
                  <Icon name="plus" size={18} />
                </button>
              </form>
              <button
                className="combat-roll"
                disabled={busy || !combat.participants.length}
                onClick={() => void action("/roll-initiative")}
              >
                <Icon name="dice" size={17} />
                Rolar iniciativa de todos
              </button>
              <p className="combat-note">Rolar novamente reorganiza a ordem e volta à rodada 1.</p>
              <button className="combat-end danger" disabled={busy} onClick={() => setEnding(true)}>
                Encerrar encontro
              </button>
            </>
          )}
        </>
      )}
      {ending && (
        <Modal
          title="Encerrar este encontro?"
          onClose={() => {
            if (!busy) setEnding(false);
          }}
        >
          <div className="room-form">
            <p>A lista de iniciativa será encerrada. As fichas e os tokens continuam na mesa.</p>
            <button className="danger" disabled={busy} onClick={() => void action("/end")}>
              Encerrar combate
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}
