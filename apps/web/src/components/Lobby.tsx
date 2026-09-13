import { useEffect, useState, type FormEvent } from "react";
import { api, setToken } from "../lib/api";
import { publicAssetUrl } from "../lib/assets";
import {
  campaignBackupFilename,
  downloadCampaignBackup,
  parseCampaignBackup,
} from "../lib/campaignBackup";
import {
  useSession,
  type RoomResult,
  type RoomSummary,
  type Ruleset,
  type User,
} from "../store/session";
import { Brand, Icon } from "./Icon";
import { AuthPage, type AuthMode } from "./lobby/AuthPage";
import { RoomCard } from "./lobby/RoomCard";
import { RoomModal, type RoomModalKind } from "./lobby/RoomModal";
import "./Lobby.css";

export function Lobby() {
  const { user, setAuth, enterRoom, clearAuth } = useSession();
  const [mode, setMode] = useState<AuthMode>("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [campaignName, setCampaignName] = useState("");
  const [library, setLibrary] = useState("core");
  const [ruleset, setRuleset] = useState<Ruleset>("5e-2024");
  const [joinCode, setJoinCode] = useState(
    location.hash.replace("#mesa/", "").replace("#", "").split("/")[0],
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rooms, setRooms] = useState<RoomSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [retry, setRetry] = useState(0);
  const [loadFailed, setLoadFailed] = useState(false);
  const [modal, setModal] = useState<RoomModalKind | null>(null);
  const [filter, setFilter] = useState<"all" | "gm" | "player">("all");
  const [query, setQuery] = useState("");
  const [backupAction, setBackupAction] = useState<"restore" | number | null>(null);
  useEffect(() => {
    if (!user) return;
    let active = true;
    setLoading(true);
    setLoadFailed(false);
    setError(null);
    api<{ rooms: RoomSummary[] }>("/rooms")
      .then(async (res) => {
        if (!active) return;
        setRooms(
          res.rooms.map((room) => ({ ...room, backgroundUrl: publicAssetUrl(room.backgroundUrl) })),
        );
        const code = location.hash.replace("#mesa/", "").split("/")[0];
        if (res.rooms.some((room) => room.code === code)) {
          const result = await api<RoomResult>("/rooms/join", {
            method: "POST",
            body: JSON.stringify({ code }),
          });
          if (active) enterRoom(result);
        }
      })
      .catch((e) => {
        if (active) {
          setError(e.message);
          setLoadFailed(true);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [user, enterRoom, retry]);
  async function auth(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ user: User; token: string }>(
        mode === "login" ? "/login" : "/register",
        {
          method: "POST",
          body: JSON.stringify(mode === "login" ? { email, password } : { name, email, password }),
        },
      );
      setToken(res.token);
      setAuth(res.user, res.token);
      setPassword("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível entrar.");
    } finally {
      setBusy(false);
    }
  }
  async function openRoom(code: string) {
    setBusy(true);
    setError(null);
    try {
      const res = await api<RoomResult>("/rooms/join", {
        method: "POST",
        body: JSON.stringify({ code: code.trim() }),
      });
      location.hash = "mesa/" + res.room.code;
      enterRoom(res);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Confira o código da mesa.");
    } finally {
      setBusy(false);
    }
  }
  async function createRoom(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api<RoomResult>("/rooms", {
        method: "POST",
        body: JSON.stringify({
          name: campaignName.trim(),
          ruleset,
          library,
          sceneName: "Cena inicial",
        }),
      });
      location.hash = "mesa/" + res.room.code;
      enterRoom(res);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível criar a mesa.");
    } finally {
      setBusy(false);
    }
  }
  async function restoreBackup(file?: File) {
    if (!file || busy) return;
    setBusy(true);
    setBackupAction("restore");
    setError(null);
    try {
      const archive = parseCampaignBackup(await file.text());
      const restored = await api<{ room: { code: string } }>("/campaigns/import", {
        method: "POST",
        body: JSON.stringify({ archive }),
      });
      await openRoom(restored.room.code);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível restaurar o backup.");
    } finally {
      setBackupAction(null);
      setBusy(false);
    }
  }
  async function exportBackup(room: RoomSummary) {
    if (busy || room.role !== "gm") return;
    setBusy(true);
    setBackupAction(room.campaignId);
    setError(null);
    try {
      const archive = await api<unknown>(`/campaigns/${room.campaignId}/export`);
      downloadCampaignBackup(archive, campaignBackupFilename(room.name, room.campaignId));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível exportar o backup.");
    } finally {
      setBackupAction(null);
      setBusy(false);
    }
  }
  async function logout() {
    setBusy(true);
    try {
      await api("/logout", { method: "POST" });
      setToken(null);
      clearAuth();
      location.hash = "";
      setRooms([]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível sair.");
    } finally {
      setBusy(false);
    }
  }
  if (!user) {
    return (
      <AuthPage
        mode={mode}
        setMode={(nextMode) => {
          setMode(nextMode);
          setError(null);
        }}
        name={name}
        setName={setName}
        email={email}
        setEmail={setEmail}
        password={password}
        setPassword={setPassword}
        busy={busy}
        error={error}
        onSubmit={auth}
      />
    );
  }
  const visible = rooms.filter(
    (r) =>
      (filter === "all" || r.role === filter) &&
      r.name.toLocaleLowerCase("pt-BR").includes(query.toLocaleLowerCase("pt-BR")),
  );
  return (
    <div className="library">
      <header className="library__header">
        <Brand />
        <span className="library__nav">
          <Icon name="home" size={17} /> Minhas mesas
        </span>
        <div className="account">
          <span className="avatar">{user.name.slice(0, 2).toUpperCase()}</span>
          <span>
            {user.name}
            <small>Aventureiro</small>
          </span>
          <button
            className="icon-button"
            onClick={() => void logout()}
            disabled={busy}
            aria-label="Sair da conta"
            title="Sair da conta"
          >
            <Icon name="logout" size={18} />
          </button>
        </div>
      </header>
      <main className="library__main">
        <section className="library-hero">
          <div>
            <span className="eyebrow">
              QUE BOM TER VOCÊ POR AQUI, {user.name.split(" ")[0].toUpperCase()}
            </span>
            <h1>
              A próxima história
              <br />
              está esperando.
            </h1>
            <p>Suas campanhas, seu grupo, seu próximo grande momento.</p>
            <button
              className="primary"
              onClick={() => {
                setError(null);
                setModal("create");
              }}
            >
              <Icon name="plus" size={18} /> Criar uma mesa
            </button>
            <button
              className="hero-join"
              onClick={() => {
                setError(null);
                setModal("join");
              }}
            >
              Entrar com código <Icon name="arrow" size={17} />
            </button>
            <label className="hero-join">
              {backupAction === "restore" ? "Restaurando backup…" : "Restaurar backup"}
              <input
                type="file"
                accept="application/json,.json"
                hidden
                disabled={busy}
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  event.target.value = "";
                  void restoreBackup(file);
                }}
              />
            </label>
          </div>
        </section>
        <section className="library__collection">
          <div className="section-heading">
            <div>
              <h2>
                Suas mesas <span className="count">{rooms.length}</span>
              </h2>
              <p>A aventura continua de onde vocês pararam.</p>
            </div>
            <label className="search-field">
              <Icon name="search" size={18} />
              <input
                aria-label="Buscar uma mesa"
                placeholder="Buscar uma mesa…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </label>
          </div>
          <div className="library__filters" aria-label="Filtrar mesas">
            {(
              [
                { id: "all", name: "Todas as mesas" },
                { id: "gm", name: "Como mestre" },
                { id: "player", name: "Como jogador" },
              ] as const
            ).map((f) => (
              <button key={f.id} aria-pressed={filter === f.id} onClick={() => setFilter(f.id)}>
                {f.name}{" "}
                <span>{rooms.filter((r) => f.id === "all" || f.id === r.role).length}</span>
              </button>
            ))}
          </div>
          {error && !modal && (
            <div className="notice notice--error" role="alert">
              {error}
              {loadFailed && (
                <button onClick={() => setRetry((n) => n + 1)}>Tentar novamente</button>
              )}
            </div>
          )}
          {loading ? (
            <div className="room-grid" aria-label="Carregando mesas" aria-busy="true">
              {[1, 2, 3].map((i) => (
                <div className="room-skeleton" key={i} />
              ))}
            </div>
          ) : !loadFailed && visible.length === 0 ? (
            <div className="library-empty">
              <span className="empty-symbol">
                <Icon name="map" size={34} />
              </span>
              <h3>{rooms.length ? "Nenhuma mesa por aqui." : "Um mundo inteiro para começar."}</h3>
              <p>
                {rooms.length
                  ? "Experimente outro nome ou filtro."
                  : "Crie sua primeira campanha ou use o código que seu mestre compartilhou."}
              </p>
              <button
                onClick={() => {
                  setError(null);
                  setModal(rooms.length ? "create" : "join");
                }}
              >
                {rooms.length ? "Criar uma mesa" : "Tenho um código de convite"}
                <Icon name="arrow" size={16} />
              </button>
            </div>
          ) : (
            <div className="room-grid">
              {visible.map((room) => (
                <RoomCard
                  key={room.code}
                  room={room}
                  busy={busy}
                  backupAction={backupAction}
                  onOpen={(selectedRoom) => void openRoom(selectedRoom.code)}
                  onBackup={(selectedRoom) => void exportBackup(selectedRoom)}
                />
              ))}
            </div>
          )}
        </section>
        <footer className="library__footer">
          <Icon name="dice" size={18} />
          <span>Os melhores momentos não estão no livro de regras.</span>
          <span>VTT Edson · D&D 5e</span>
        </footer>
      </main>
      {modal && (
        <RoomModal
          kind={modal}
          busy={busy}
          error={error}
          library={library}
          setLibrary={setLibrary}
          campaignName={campaignName}
          setCampaignName={setCampaignName}
          ruleset={ruleset}
          setRuleset={setRuleset}
          joinCode={joinCode}
          setJoinCode={setJoinCode}
          onClose={() => {
            if (!busy) {
              setModal(null);
              setError(null);
            }
          }}
          onCreate={createRoom}
          onJoin={() => void openRoom(joinCode)}
        />
      )}
    </div>
  );
}
