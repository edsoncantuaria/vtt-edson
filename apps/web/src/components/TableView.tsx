import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { isManagerRole, useSession, type Panel, type SceneSummary } from "../store/session";
import { updateScene } from "../lib/scene";
import { useSceneSync } from "../lib/useSceneSync";
import { Icon } from "./Icon";
import { DiceTray } from "./DiceTray";
import { AmbiencePlayer } from "./AmbiencePlayer";
import { api } from "../lib/api";
import { pluginRegistry } from "../lib/plugins";
import { HelpDialog, InviteDialog } from "./table-view/TableDialogs";
import { TableFooter } from "./table-view/TableFooter";
import { TableHeader } from "./table-view/TableHeader";
import { SessionPanel } from "./table-view/SessionPanel";
import { TABLE_TOOLS } from "./table-view/tableViewConfig";
import { ToolRail } from "./table-view/ToolRail";
import { MacroHotbar } from "./table-view/MacroHotbar";
import { CanvasObjectInspector } from "./table-view/CanvasObjectInspector";
import { useVttTable } from "./table-view/useVttTable";
import "./TableView.css";

export function TableView() {
  const {
    sceneId,
    sceneName,
    campaignName,
    campaignId,
    roomCode,
    role,
    canEditScene,
    state,
    backgroundUrl,
    tool,
    panel,
    actors,
    user,
    ruleset,
    combat,
    setTool,
    setPanel,
    setSelectedActorId,
    setError,
    enterScene,
    error,
  } = useSession();
  const upload = useRef<HTMLInputElement>(null);
  const [diceOpen, setDiceOpen] = useState(false);
  const [panelOpen, setPanelOpen] = useState(true);
  const [helpOpen, setHelpOpen] = useState(false);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [tokenActor, setTokenActor] = useState("");
  const [scenes, setScenes] = useState<SceneSummary[]>([]);
  const connection = useSceneSync();
  const gm = isManagerRole(role);
  const sceneEditor = gm || canEditScene;
  const availableTools = useMemo(
    () => TABLE_TOOLS.filter((item) => !item.gm || sceneEditor),
    [sceneEditor],
  );
  const activeTool = TABLE_TOOLS.find((item) => item.id === tool)!;
  const report = useCallback(
    (e: unknown) => setError(e instanceof Error ? e.message : "Não foi possível concluir a ação."),
    [setError],
  );
  useEffect(() => {
    if (!campaignId) return;
    const controller = new AbortController();
    let pending = false;
    async function refreshScenes() {
      if (pending || controller.signal.aborted) return;
      pending = true;
      try {
        const result = await api<{ scenes: SceneSummary[] }>(`/campaigns/${campaignId}/scenes`, {
          signal: controller.signal,
        });
        if (!controller.signal.aborted) setScenes(result.scenes);
      } catch (error) {
        if (!controller.signal.aborted) report(error);
      } finally {
        pending = false;
      }
    }
    void refreshScenes();
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void refreshScenes();
    }, 15000);
    return () => {
      controller.abort();
      window.clearInterval(timer);
    };
  }, [campaignId, report]);
  async function switchScene(id: number) {
    try {
      const result = await api<{ scene: SceneSummary }>(`/scenes/${id}`);
      enterScene(result.scene);
      await pluginRegistry.hooks.emit("scene:entered", { sceneId: id });
    } catch (error) {
      report(error);
    }
  }
  function choosePanel(next: Panel) {
    setPanel(next);
    setPanelOpen(true);
  }
  const {
    host,
    table,
    zoom,
    selectedTokenIds,
    lastTemplate,
    preparing,
    effect,
    dismissEffect,
    selectedCanvasObject,
    selectCanvasObject,
  } = useVttTable({
    sceneId,
    role,
    userId: user?.id,
    tokenActorId: tokenActor,
    canEditScene: sceneEditor,
    report,
    onActorPanelOpen: useCallback(() => setPanelOpen(true), []),
  });
  useEffect(() => {
    const key = (e: KeyboardEvent) => {
      if (
        (e.target as HTMLElement).closest("input,textarea,select,[contenteditable],dialog") ||
        e.ctrlKey ||
        e.metaKey ||
        e.altKey
      )
        return;
      const selected = availableTools.find((t) => t.key.toLowerCase() === e.key.toLowerCase());
      if (selected) {
        e.preventDefault();
        setTool(selected.id);
      }
      if (e.key === "Escape") {
        setDiceOpen(false);
        setTool("select");
      }
      if (e.key === "?") setHelpOpen(true);
    };
    window.addEventListener("keydown", key);
    return () => window.removeEventListener("keydown", key);
  }, [availableTools, setTool]);
  async function uploadMap(file: File | undefined) {
    if (!file || !sceneId) return;
    if (file.size > 10 * 1024 * 1024) {
      setError("Escolha um mapa de até 10 MB.");
      return;
    }
    setUploading(true);
    try {
      const data = new FormData();
      data.append("background", file);
      await updateScene(sceneId, "/background", data);
    } catch (e) {
      report(e);
    } finally {
      setUploading(false);
    }
  }
  const ownActor = actors.find((a) => a.type === "character" && a.ownerUserId === user?.id);
  const activeTurn = combat?.participants[combat.turn];
  return (
    <main className={"table " + (!panelOpen ? "table--focus" : "")}>
      <TableHeader
        campaignName={campaignName}
        ruleset={ruleset}
        sceneName={sceneName}
        connection={connection}
        gm={gm}
        userName={user?.name ?? ""}
        onHome={() => {
          location.hash = "";
          useSession.getState().leaveScene();
        }}
        onInvite={() => {
          setCopied(false);
          setInviteOpen(true);
        }}
      />
      <div className="table-workspace">
        <ToolRail
          tools={availableTools}
          activeTool={tool}
          gm={gm}
          onTool={setTool}
          onScene={() => choosePanel("scene")}
          onHelp={() => setHelpOpen(true)}
        />
        <section className="table-stage" aria-label="Mapa da sessão">
          <div
            className="table-canvas"
            ref={host}
            role="img"
            aria-label="Mapa tático interativo. Use o painel Cena para gerenciar tokens ou as ferramentas para navegar."
          />
          <div className="stage-top">
            <div className="scene-chip">
              <Icon name="map" size={17} />
              <label className="sr-only" htmlFor="scene-switcher">
                Cena ativa
              </label>
              <select
                id="scene-switcher"
                value={sceneId ?? ""}
                onChange={(event) => void switchScene(Number(event.target.value))}
              >
                {(scenes.length ? scenes : sceneId ? [{ id: sceneId, name: sceneName }] : []).map(
                  (scene) => (
                    <option value={scene.id} key={scene.id}>
                      {scene.name}
                    </option>
                  ),
                )}
              </select>
              <small>{gm ? "VISÃO DO MESTRE" : "EXPLORAÇÃO"}</small>
            </div>
            <button
              className="focus-button icon-button"
              onClick={() => setPanelOpen(!panelOpen)}
              aria-label={panelOpen ? "Recolher painel lateral" : "Abrir painel lateral"}
              title="Alternar painel"
            >
              <Icon name={panelOpen ? "chevron" : "book"} size={18} />
            </button>
          </div>
          {combat && (
            <button className="turn-banner" onClick={() => choosePanel("combat")}>
              <Icon name="swords" size={17} />
              <span>RODADA {combat.round}</span>
              <strong>{activeTurn ? "Turno de " + activeTurn.name : "Adicione combatentes"}</strong>
              <Icon name="chevron" size={16} />
            </button>
          )}
          {preparing && (
            <div className="stage-loading" role="status">
              Preparando o mapa…
            </div>
          )}
          {!preparing && !backgroundUrl && state.tokens.length === 0 && tool === "select" && (
            <div className="stage-welcome">
              <span className="empty-symbol">
                <Icon name="map" size={34} />
              </span>
              <h2>
                {sceneEditor ? "Dê vida ao seu mundo." : "A aventura está prestes a começar."}
              </h2>
              <p>
                {sceneEditor
                  ? "Adicione um mapa e prepare os objetos desta cena."
                  : "Prepare sua ficha enquanto o mestre organiza a cena."}
              </p>
              {sceneEditor ? (
                <button
                  className="primary"
                  disabled={uploading}
                  onClick={() => upload.current?.click()}
                >
                  <Icon name="upload" size={17} />
                  {uploading ? "Enviando…" : "Adicionar mapa"}
                </button>
              ) : (
                <button className="primary" onClick={() => choosePanel("actors")}>
                  <Icon name="shield" size={17} />
                  Minha ficha
                </button>
              )}
              <small>
                {sceneEditor
                  ? "JPG, PNG ou WebP · até 10 MB"
                  : "As áreas aparecem conforme o mestre as revela."}
              </small>
            </div>
          )}
          {tool === "token" && sceneEditor && (
            <div className="token-picker">
              <label>
                Colocar no mapa
                <select value={tokenActor} onChange={(e) => setTokenActor(e.target.value)}>
                  <option value="">Token sem ficha</option>
                  {actors.map((a) => (
                    <option value={a.id} key={a.id}>
                      {a.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          )}
          {error && (
            <div className="table-error notice notice--error" role="alert">
              <span>{error}</span>
              <button
                className="icon-button"
                aria-label="Dispensar erro"
                onClick={() => setError(null)}
              >
                <Icon name="close" size={16} />
              </button>
            </div>
          )}
          <div className="stage-bottom">
            <div className="zoom-control">
              <button
                className="icon-button"
                onClick={() => table.current?.zoom(0.8)}
                aria-label="Diminuir zoom"
              >
                <Icon name="minus" size={16} />
              </button>
              <span>{Math.round(zoom * 100)}%</span>
              <button
                className="icon-button"
                onClick={() => table.current?.zoom(1.25)}
                aria-label="Aumentar zoom"
              >
                <Icon name="plus" size={16} />
              </button>
              <button
                className="icon-button"
                onClick={() => table.current?.fit()}
                aria-label="Enquadrar mapa"
                title="Enquadrar mapa"
              >
                <Icon name="fit" size={16} />
              </button>
            </div>
            <span className="grid-info">
              <Icon name="grid" size={14} />
              {state.grid.size}px · {state.grid.snap ? "Encaixe ativo" : "Movimento livre"}
            </span>
            {selectedTokenIds.length > 0 && (
              <span className="grid-info">
                <Icon name="users" size={14} />
                {selectedTokenIds.length} {selectedTokenIds.length === 1 ? "alvo" : "alvos"}
                {lastTemplate ? ` · ${Math.round(lastTemplate.distanceFeet * 10) / 10} ft` : ""}
              </span>
            )}
          </div>
          {diceOpen && (
            <div className="dice-popover">
              <button
                className="icon-button dice-close"
                aria-label="Fechar dados"
                onClick={() => setDiceOpen(false)}
              >
                <Icon name="close" size={17} />
              </button>
              <DiceTray />
            </div>
          )}
          {effect && (
            <div className="action-effect" role="status" aria-label={effect.label}>
              <img src={effect.url} alt={effect.label} onError={dismissEffect} />
              <span>{effect.label}</span>
              <button className="icon-button" onClick={dismissEffect} aria-label="Fechar efeito">
                <Icon name="close" size={17} />
              </button>
            </div>
          )}
          {sceneId && selectedCanvasObject && sceneEditor && (
            <CanvasObjectInspector
              key={`${selectedCanvasObject.kind}:${selectedCanvasObject.id}`}
              sceneId={sceneId}
              state={state}
              selection={selectedCanvasObject}
              onClear={() => selectCanvasObject(null)}
              onError={report}
            />
          )}
          <MacroHotbar center={() => table.current?.centerPoint() ?? { x: 350, y: 350 }} />
          <AmbiencePlayer />
        </section>
        {panelOpen && (
          <SessionPanel
            panel={panel}
            gm={gm}
            actorCount={actors.length}
            combat={combat}
            uploading={uploading}
            scenes={scenes}
            onPanel={choosePanel}
            onUpload={() => upload.current?.click()}
            center={() => table.current?.centerPoint() ?? { x: 350, y: 350 }}
            onScenesChange={setScenes}
            onSceneChange={switchScene}
          />
        )}
      </div>
      <TableFooter
        userName={user?.name ?? ""}
        gm={gm}
        ownActorName={ownActor?.name}
        activeTool={activeTool}
        diceOpen={diceOpen}
        onOwnActor={() => {
          if (ownActor) setSelectedActorId(ownActor.id);
        }}
        onPanel={choosePanel}
        onDice={() => setDiceOpen((open) => !open)}
      />
      <input
        ref={upload}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        hidden
        onChange={(e) => {
          void uploadMap(e.target.files?.[0]);
          e.target.value = "";
        }}
      />
      {inviteOpen && (
        <InviteDialog
          roomCode={roomCode}
          copied={copied}
          onClose={() => setInviteOpen(false)}
          onCopy={() => {
            void navigator.clipboard
              .writeText(roomCode ?? "")
              .then(() => setCopied(true))
              .catch(() => setError("Não foi possível copiar. Selecione o código acima."));
          }}
        />
      )}
      {helpOpen && <HelpDialog gm={gm} tools={availableTools} onClose={() => setHelpOpen(false)} />}
    </main>
  );
}
