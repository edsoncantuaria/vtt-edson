import type { RoomSummary } from "../../store/session";
import { Icon } from "../Icon";

export function RoomCard({
  room,
  busy,
  backupAction,
  onOpen,
  onBackup,
}: {
  room: RoomSummary;
  busy: boolean;
  backupAction: "restore" | number | null;
  onOpen: (room: RoomSummary) => void;
  onBackup: (room: RoomSummary) => void;
}) {
  return (
    <article className="room-card">
      <div
        className="room-card__art"
        style={{
          backgroundImage: `url("${room.backgroundUrl ?? "/adventure-citadel-gemini.jpeg"}")`,
        }}
      >
        <span className={`role-badge ${room.role === "gm" ? "role-badge--gm" : ""}`}>
          <Icon name={room.role === "gm" ? "shield" : "users"} size={14} />
          {room.role === "gm" ? "Mestre" : "Jogador"}
        </span>
        <span className="room-card__edition">
          D&D 5e · {room.ruleset === "5e-2024" ? "2024" : "2014"}
        </span>
      </div>
      <div className="room-card__body">
        <h3>{room.name}</h3>
        <p>
          <Icon name="map" size={14} />
          {room.sceneName}
        </p>
        <div className="room-card__group">
          <span>
            <Icon name="users" size={15} />
            {room.memberCount} {room.memberCount === 1 ? "participante" : "participantes"}
          </span>
          <span>Mestre: {room.gmName}</span>
        </div>
        <button className="room-card__open" disabled={busy} onClick={() => onOpen(room)}>
          Abrir mesa <Icon name="arrow" size={18} />
        </button>
        {room.role === "gm" && (
          <button
            className="room-card__backup"
            disabled={busy}
            onClick={() => onBackup(room)}
            title="Baixar um backup completo desta campanha"
          >
            <Icon name="download" size={16} />
            {backupAction === room.campaignId ? "Gerando backup…" : "Baixar backup"}
          </button>
        )}
      </div>
    </article>
  );
}
