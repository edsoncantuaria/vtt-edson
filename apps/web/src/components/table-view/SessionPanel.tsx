import type { Combat } from "@vtt/core";
import type { Panel, SceneSummary } from "../../store/session";
import { ActorSheet } from "../ActorSheet";
import { ChatPanel } from "../ChatPanel";
import { CombatTracker } from "../CombatTracker";
import { Compendium } from "../Compendium";
import { Icon } from "../Icon";
import { JournalPanel } from "../JournalPanel";
import { ScenePanel } from "../ScenePanel";
import { SESSION_PANELS } from "./tableViewConfig";

function panelTitle(panel: Panel, gm: boolean): string {
  if (panel === "scene") return "Preparar cena";
  if (panel === "actors") return gm ? "Personagens e criaturas" : "Suas fichas";
  if (panel === "chat") return "Conversa da mesa";
  if (panel === "combat") return "Ordem de iniciativa";
  if (panel === "journal") return "Diário da campanha";
  return "Compêndio";
}

function panelMeta(panel: Panel, gm: boolean, actorCount: number): string {
  if (panel === "chat") return "COMPARTILHADA";
  if (panel === "actors") return `${actorCount} FICHAS`;
  if (panel === "compendium") return "2014 · 2024";
  if (panel === "journal") return "COMPARTILHADO";
  return gm ? "MESTRE" : "JOGADOR";
}

export function SessionPanel({
  panel,
  gm,
  actorCount,
  combat,
  uploading,
  scenes,
  onPanel,
  onUpload,
  center,
  onScenesChange,
  onSceneChange,
}: {
  panel: Panel;
  gm: boolean;
  actorCount: number;
  combat: Combat | null;
  uploading: boolean;
  scenes: SceneSummary[];
  onPanel: (panel: Panel) => void;
  onUpload: () => void;
  center: () => { x: number; y: number };
  onScenesChange: (scenes: SceneSummary[]) => void;
  onSceneChange: (id: number) => Promise<void>;
}) {
  return (
    <aside className="session-panel">
      <nav className="panel-tabs" aria-label="Painéis da sessão">
        {SESSION_PANELS.map((item) => (
          <button key={item.id} aria-pressed={panel === item.id} onClick={() => onPanel(item.id)}>
            <Icon name={item.icon} size={19} />
            <span>{item.label}</span>
            {item.id === "combat" && combat && <i />}
          </button>
        ))}
      </nav>
      <div className="panel-heading">
        <h2>{panelTitle(panel, gm)}</h2>
        <span>{panelMeta(panel, gm, actorCount)}</span>
      </div>
      <div className={`panel-body panel-body--${panel}`}>
        {panel === "chat" && <ChatPanel />}
        {panel === "actors" && <ActorSheet />}
        {panel === "combat" && <CombatTracker />}
        {panel === "compendium" && <Compendium />}
        {panel === "journal" && <JournalPanel />}
        {panel === "scene" && gm && (
          <ScenePanel
            uploading={uploading}
            onUpload={onUpload}
            center={center}
            scenes={scenes}
            onScenesChange={onScenesChange}
            onSceneChange={onSceneChange}
          />
        )}
      </div>
    </aside>
  );
}
