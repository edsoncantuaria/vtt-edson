import { useEffect, useRef, useState } from "react";
import type { Actor, ActorSystem } from "@vtt/core";
import { CharacterImportSchema, buildModifierFormula } from "@vtt/core";
import { api } from "../lib/api";
import { rollToChat } from "../lib/roll";
import { updateScene } from "../lib/scene";
import { pluginRegistry } from "../lib/plugins";
import { isManagerRole, useSession } from "../store/session";
import { Icon } from "./Icon";
import { Modal } from "./Modal";
import { ActorEditor } from "./ActorEditor";
import "./ActorSheet.css";
import { MonsterStatBlock } from "./MonsterStatBlock";
import { ClassicSheet } from "./ClassicSheet";
import { CharacterWizard } from "./CharacterWizard";
import { CharacterLevelUp } from "./CharacterLevelUp";
import { ActorQuickSheet } from "./actor-sheet/ActorQuickSheet";
import type { ActorSheetTab } from "./actor-sheet/actorSheetTypes";
export function ActorSheet() {
  const {
    actors,
    role,
    user,
    campaignId,
    sceneId,
    roomCode,
    selectedActorId,
    targetActorIds,
    setSelectedActorId,
    upsertActor,
    removeActor,
    setError,
    ruleset,
  } = useSession();
  const [wizard, setWizard] = useState(false);
  const [leveling, setLeveling] = useState(false);
  const [view, setView] = useState<"quick" | "classic">("quick");
  const [tab, setTab] = useState<ActorSheetTab>("Atributos");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [name, setName] = useState("");
  const [type, setType] = useState<Actor["type"]>("character");
  const [busy, setBusy] = useState(false);
  const [amount, setAmount] = useState(1);
  const [mode, setMode] = useState("normal");
  const [query, setQuery] = useState("");
  const actionRequest = useRef<{ key: string; id: string } | null>(null);
  const importer = useRef<HTMLInputElement>(null);
  const actor = actors.find((a) => a.id === selectedActorId);
  const actorId = actor?.id;
  const canEdit = actor && (isManagerRole(role) || actor.ownerUserId === user?.id);
  const report = (e: unknown) =>
    setError(e instanceof Error ? e.message : "Não foi possível concluir a ação.");
  const form = (mod: number) =>
    buildModifierFormula(mod, {
      advantage: mode === "advantage",
      disadvantage: mode === "disadvantage",
    });
  useEffect(() => {
    if (actorId) void pluginRegistry.hooks.emit("actor:opened", { actorId });
  }, [actorId]);
  async function uploadPortrait(file: File) {
    if (!actor || busy) return;
    setBusy(true);
    try {
      const formData = new FormData();
      formData.append("image", file);
      const result = await api<{ actor: Actor }>(`/actors/${actor.id}/image`, {
        method: "POST",
        formData,
      });
      upsertActor(result.actor);
    } catch (error) {
      report(error);
    } finally {
      setBusy(false);
    }
  }
  async function roll(formula: string, label: string) {
    if (!sceneId || busy) return;
    setBusy(true);
    try {
      await rollToChat(sceneId, formula, label.slice(0, 80));
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  async function executeAction(actionId: string) {
    if (!actor || !sceneId || busy) return;
    setBusy(true);
    const key = `${sceneId}:${actor.id}:${actionId}`;
    if (actionRequest.current?.key !== key)
      actionRequest.current = { key, id: crypto.randomUUID() };
    try {
      await pluginRegistry.hooks.emit("action:before", {
        sceneId,
        actorId: actor.id,
        actionId,
      });
      const result = await updateScene(sceneId, "/actions", {
        actorId: actor.id,
        actionId,
        requestId: actionRequest.current.id,
        mode,
        targetActorIds,
      });
      const message = (result as { message?: { id?: unknown } }).message;
      await pluginRegistry.hooks.emit("action:after", {
        sceneId,
        actorId: actor.id,
        actionId,
        ...(typeof message?.id === "string" ? { messageId: message.id } : {}),
      });
      actionRequest.current = null;
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  async function change(fn: (system: ActorSystem) => void) {
    if (!actor || !canEdit || busy) return;
    setBusy(true);
    const system = structuredClone(actor.system);
    fn(system);
    try {
      const res = await api<{ actor: Actor }>("/actors/" + actor.id, {
        method: "PATCH",
        body: JSON.stringify({ system, revision: actor.revision ?? 0 }),
      });
      upsertActor(res.actor);
      await pluginRegistry.hooks.emit("actor:updated", { actorId: res.actor.id });
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  async function create(data: object) {
    if (!campaignId || busy) return;
    setBusy(true);
    try {
      const res = await api<{ actor: Actor }>("/campaigns/" + campaignId + "/actors", {
        method: "POST",
        body: JSON.stringify(data),
      });
      upsertActor(res.actor);
      setSelectedActorId(res.actor.id);
      setCreating(false);
      setName("");
      setEditing(true);
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  async function importFile(file?: File) {
    if (!file) return;
    try {
      const parsed = CharacterImportSchema.safeParse(JSON.parse(await file.text()));
      if (!parsed.success) throw new Error("Ficha inválida. Use um arquivo exportado pelo VTT.");
      await create(parsed.data);
    } catch (e) {
      report(e);
    }
  }
  async function exportFile() {
    if (!actor) return;
    try {
      const data = await api("/actors/" + actor.id + "/export");
      const url = URL.createObjectURL(
        new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }),
      );
      const link = document.createElement("a");
      link.href = url;
      link.download = actor.name + ".json";
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      report(e);
    }
  }
  async function destroy() {
    if (!actor || busy) return;
    setBusy(true);
    try {
      await api("/actors/" + actor.id, { method: "DELETE" });
      removeActor(actor.id);
      setDeleting(false);
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  if (wizard) return <CharacterWizard onClose={() => setWizard(false)} />;
  return (
    <div className="characters">
      {!actor ? (
        <>
          <div className="characters-tools">
            <button onClick={() => setWizard(true)}>Criar com assistente</button>
            <button className="primary" onClick={() => setCreating(true)}>
              <Icon name="plus" size={16} />
              Nova ficha
            </button>
            <button
              className="icon-button"
              title="Importar ficha JSON"
              aria-label="Importar ficha JSON"
              onClick={() => importer.current?.click()}
            >
              <Icon name="upload" size={17} />
            </button>
          </div>
          {actors.length > 3 && (
            <label className="search-field">
              <Icon name="search" size={16} />
              <input
                aria-label="Buscar ficha"
                placeholder="Buscar personagem…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </label>
          )}
          {!actors.length && (
            <div className="panel-empty">
              <Icon name="shield" size={38} />
              <h3>
                {isManagerRole(role) ? "Quem faz parte desta história?" : "Seu herói começa aqui."}
              </h3>
              <p>
                {isManagerRole(role)
                  ? "Crie personagens, aliados e criaturas. Depois, coloque-os na cena."
                  : "Crie sua ficha e peça ao mestre para colocar seu token no mapa."}
              </p>
            </div>
          )}
          <div className="character-list">
            {actors
              .filter((a) => a.name.toLowerCase().includes(query.toLowerCase()))
              .map((a) => (
                <button
                  className={"character-card character-card--" + a.type}
                  key={a.id}
                  onClick={() => setSelectedActorId(a.id)}
                >
                  <span className="character-portrait">
                    <Icon name={a.type === "monster" ? "swords" : "shield"} size={23} />
                  </span>
                  <span className="character-card__info">
                    <b>{a.name}</b>
                    <small>
                      {a.type === "character"
                        ? (a.system.bio.class || "Personagem") + " · Nível " + a.system.bio.level
                        : a.type === "npc"
                          ? "Aliado / NPC"
                          : "Criatura"}
                    </small>
                    <span className="mini-health">
                      <i
                        style={{
                          width:
                            Math.max(
                              0,
                              Math.min(
                                100,
                                (a.system.hp.value / Math.max(1, a.system.hp.max)) * 100,
                              ),
                            ) + "%",
                        }}
                      />
                    </span>
                  </span>
                  <span className="character-card__hp">
                    {a.system.hp.value}
                    <small>/{a.system.hp.max} PV</small>
                  </span>
                  <Icon name="chevron" size={14} />
                </button>
              ))}
          </div>
        </>
      ) : (
        <>
          {isManagerRole(role) && (
            <label>
              <input
                type="checkbox"
                checked={!!actor.shared}
                disabled={busy}
                onChange={(event) => {
                  const shared = event.target.checked;
                  setBusy(true);
                  void api<{ actor: Actor }>(`/actors/${actor.id}`, {
                    method: "PATCH",
                    body: JSON.stringify({ shared }),
                  })
                    .then((result) => upsertActor(result.actor))
                    .catch(report)
                    .finally(() => setBusy(false));
                }}
              />{" "}
              Compartilhar leitura da ficha com o grupo
            </label>
          )}
          {canEdit && (
            <label>
              Retrato e token (PNG, JPG ou WebP, até 5 MB)
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                disabled={busy}
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) void uploadPortrait(file);
                  event.target.value = "";
                }}
              />
            </label>
          )}
          <div className="character-back">
            <button className="ghost" onClick={() => setSelectedActorId(null)}>
              <Icon name="back" size={15} />
              Todas as fichas
            </button>
            <button
              className="icon-button"
              aria-label="Exportar ficha"
              title="Exportar ficha"
              onClick={() => void exportFile()}
            >
              <Icon name="download" size={16} />
            </button>
            <button
              className="icon-button"
              aria-label="Abrir ficha em outra aba"
              title="Abrir ficha em outra aba"
              onClick={() => {
                if (roomCode && actor) {
                  window.open(
                    `${location.origin}${location.pathname}#mesa/${roomCode}/ficha/${actor.id}`,
                    "_blank",
                    "noopener",
                  );
                }
              }}
            >
              <Icon name="book" size={16} />
            </button>
            {canEdit && (
              <button
                className="icon-button"
                aria-label="Evoluir personagem"
                title="Evoluir personagem"
                disabled={actor.type !== "character" || actor.system.bio.level >= 20}
                onClick={() => setLeveling(true)}
              >
                <Icon name="spark" size={17} />
              </button>
            )}
            {canEdit && (
              <button
                className="icon-button"
                aria-label="Editar ficha"
                title="Editar ficha"
                onClick={() => setEditing(true)}
              >
                <Icon name="settings" size={17} />
              </button>
            )}
          </div>
          <div className="segmented sheet-view">
            <button aria-pressed={view === "quick"} onClick={() => setView("quick")}>
              Modo de jogo
            </button>
            <button aria-pressed={view === "classic"} onClick={() => setView("classic")}>
              Ficha clássica
            </button>
          </div>
          {view === "classic" ? (
            actor.type === "character" ? (
              <ClassicSheet key={actor.id} actor={actor} canEdit={!!canEdit} />
            ) : (
              <MonsterStatBlock actor={actor} />
            )
          ) : (
            <ActorQuickSheet
              actor={actor}
              ruleset={ruleset}
              canEdit={!!canEdit}
              busy={busy}
              amount={amount}
              setAmount={setAmount}
              tab={tab}
              setTab={setTab}
              mode={mode}
              setMode={setMode}
              formula={form}
              roll={roll}
              change={change}
              executeAction={executeAction}
              onEdit={() => setEditing(true)}
              onDelete={() => setDeleting(true)}
            />
          )}
        </>
      )}
      <input
        type="file"
        accept="application/json"
        ref={importer}
        hidden
        onChange={(e) => {
          void importFile(e.target.files?.[0]);
          e.target.value = "";
        }}
      />
      {creating && (
        <Modal
          title="Quem entra nesta aventura?"
          onClose={() => {
            if (!busy) setCreating(false);
          }}
        >
          <form
            className="room-form"
            onSubmit={(e) => {
              e.preventDefault();
              void create({ name: name.trim(), type });
            }}
          >
            <label>
              Nome
              <input
                autoFocus
                value={name}
                required
                maxLength={120}
                onChange={(e) => setName(e.target.value)}
                placeholder="Nome do personagem"
              />
            </label>
            {isManagerRole(role) && (
              <label>
                Tipo
                <select value={type} onChange={(e) => setType(e.target.value as Actor["type"])}>
                  <option value="character">Personagem</option>
                  <option value="npc">Aliado / NPC</option>
                  <option value="monster">Criatura</option>
                </select>
              </label>
            )}
            <button className="primary" disabled={busy}>
              Criar ficha
            </button>
          </form>
        </Modal>
      )}
      {editing && actor && canEdit && (
        <ActorEditor actor={actor} onClose={() => setEditing(false)} />
      )}
      {leveling && actor && canEdit && actor.type === "character" && (
        <CharacterLevelUp actor={actor} onClose={() => setLeveling(false)} />
      )}
      {deleting && actor && (
        <Modal
          title={"Excluir " + actor.name + "?"}
          onClose={() => {
            if (!busy) setDeleting(false);
          }}
        >
          <div className="room-form">
            <p>Esta ficha será excluída da campanha. Exporte uma cópia se quiser guardá-la.</p>
            <button className="danger" disabled={busy} onClick={() => void destroy()}>
              Excluir ficha definitivamente
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}
