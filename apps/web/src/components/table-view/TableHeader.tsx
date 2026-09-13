import type { Ruleset } from "../../store/session";
import { Icon } from "../Icon";

export type ConnectionState = "connecting" | "live" | "polling" | "offline";

const CONNECTION_LABEL: Record<ConnectionState, string> = {
  live: "Tempo real",
  offline: "Sem conexão",
  polling: "Sincronizando a cada 5s",
  connecting: "Conectando…",
};

export function TableHeader({
  campaignName,
  ruleset,
  sceneName,
  connection,
  gm,
  userName,
  onHome,
  onInvite,
}: {
  campaignName: string;
  ruleset: Ruleset;
  sceneName: string;
  connection: ConnectionState;
  gm: boolean;
  userName: string;
  onHome: () => void;
  onInvite: () => void;
}) {
  return (
    <header className="table-header">
      <button
        className="table-home icon-button"
        aria-label="Voltar para minhas mesas"
        title="Minhas mesas"
        onClick={onHome}
      >
        <Icon name="dice" size={28} />
      </button>
      <span className="header-divider" />
      <div className="table-title">
        <h1>{campaignName}</h1>
        <span>
          D&D 5e · {ruleset === "5e-2024" ? "2024" : "2014"}
          <i />
          {sceneName}
        </span>
      </div>
      <span className={`connection connection--${connection}`}>
        <i />
        {CONNECTION_LABEL[connection]}
      </span>
      <button className="invite-button" onClick={onInvite}>
        <Icon name="users" size={17} />
        Convidar
      </button>
      <span className={`role-badge ${gm ? "role-badge--gm" : ""}`}>
        <Icon name="shield" size={14} />
        {gm ? "Mestre" : "Jogador"}
      </span>
      <span className="avatar" title={userName}>
        {userName.slice(0, 2).toUpperCase()}
      </span>
    </header>
  );
}
