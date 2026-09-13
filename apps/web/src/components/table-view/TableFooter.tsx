import type { Panel } from "../../store/session";
import { Icon } from "../Icon";
import type { ToolDefinition } from "./tableViewConfig";

export function TableFooter({
  userName,
  gm,
  ownActorName,
  activeTool,
  diceOpen,
  onOwnActor,
  onPanel,
  onDice,
}: {
  userName: string;
  gm: boolean;
  ownActorName?: string;
  activeTool: ToolDefinition;
  diceOpen: boolean;
  onOwnActor: () => void;
  onPanel: (panel: Panel) => void;
  onDice: () => void;
}) {
  return (
    <footer className="table-footer">
      <div className="table-identity">
        <span className="avatar">{userName.slice(0, 2).toUpperCase()}</span>
        <span>
          {userName}
          <small>{gm ? "Conduzindo a aventura" : (ownActorName ?? "Escolha seu personagem")}</small>
        </span>
      </div>
      <p className="tool-hint">
        <Icon name={activeTool.icon} size={15} />
        {activeTool.hint}
      </p>
      <div className="quick-actions">
        {!gm && (
          <button
            onClick={() => {
              onOwnActor();
              onPanel("actors");
            }}
          >
            <Icon name="shield" size={17} />
            Minha ficha
          </button>
        )}
        <button className={diceOpen ? "active" : ""} aria-expanded={diceOpen} onClick={onDice}>
          <Icon name="dice" size={20} />
          Rolar dados
        </button>
      </div>
    </footer>
  );
}
