import { useCallback, useEffect, useState, type FormEvent } from "react";
import { api } from "../lib/api";
import { updateScene } from "../lib/scene";
import { isManagerRole, useSession } from "../store/session";

type Track = { id: number; title: string; url: string; volume: number; loop: boolean };
type Playlist = { id: number; name: string; tracks: Track[] };

export function PlaylistManager() {
  const { campaignId, sceneId, role, setError } = useSession();
  const [playlists, setPlaylists] = useState<Playlist[]>([]);
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    if (!campaignId) return;
    try {
      setPlaylists(
        (await api<{ playlists: Playlist[] }>(`/campaigns/${campaignId}/playlists`)).playlists,
      );
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível carregar playlists.");
    }
  }, [campaignId, setError]);
  useEffect(() => {
    void load();
  }, [load]);
  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!campaignId) return;
    const fields = new FormData(event.currentTarget);
    const name = String(fields.get("name") ?? "").trim();
    if (!name) return;
    setBusy(true);
    try {
      await api(`/campaigns/${campaignId}/playlists`, {
        method: "POST",
        body: JSON.stringify({ name }),
      });
      event.currentTarget.reset();
      await load();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível criar a playlist.");
    } finally {
      setBusy(false);
    }
  }
  async function addTrack(event: FormEvent<HTMLFormElement>, playlistId: number) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    setBusy(true);
    try {
      await api(`/playlists/${playlistId}/tracks`, {
        method: "POST",
        body: JSON.stringify({
          title: String(fields.get("title")),
          url: String(fields.get("url")),
          volume: Number(fields.get("volume")) / 100,
          loop: fields.get("loop") === "on",
        }),
      });
      form.reset();
      await load();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível adicionar a faixa.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <details>
      <summary>Playlists da campanha</summary>
      {playlists.map((playlist) => (
        <section key={playlist.id}>
          <h4>{playlist.name}</h4>
          {playlist.tracks.map((track) => (
            <div className="scene-object" key={track.id}>
              <span>
                {track.title}
                <small>
                  {Math.round(track.volume * 100)}%{track.loop ? " · repetir" : ""}
                </small>
              </span>
              {isManagerRole(role) && (
                <>
                  <button
                    disabled={busy || !sceneId}
                    onClick={() =>
                      sceneId &&
                      void updateScene(
                        sceneId,
                        "/audio",
                        { url: track.url, volume: track.volume, loop: track.loop },
                        "PATCH",
                      ).catch((error) =>
                        setError(error instanceof Error ? error.message : "Falha ao tocar faixa."),
                      )
                    }
                  >
                    Tocar na cena
                  </button>
                  <button
                    disabled={busy}
                    onClick={() => {
                      setBusy(true);
                      void api(`/playlist-tracks/${track.id}`, { method: "DELETE" })
                        .then(load)
                        .catch((error) =>
                          setError(
                            error instanceof Error ? error.message : "Falha ao remover faixa.",
                          ),
                        )
                        .finally(() => setBusy(false));
                    }}
                  >
                    Remover
                  </button>
                </>
              )}
            </div>
          ))}
          {isManagerRole(role) && (
            <form onSubmit={(event) => void addTrack(event, playlist.id)}>
              <label>
                Faixa
                <input name="title" required maxLength={160} />
              </label>
              <label>
                URL HTTPS
                <input name="url" type="url" required />
              </label>
              <label>
                Volume
                <input name="volume" type="range" min={0} max={100} defaultValue={50} />
              </label>
              <label className="check-label">
                <input name="loop" type="checkbox" />
                Repetir
              </label>
              <button disabled={busy}>Adicionar faixa</button>
            </form>
          )}
        </section>
      ))}
      {isManagerRole(role) && (
        <form onSubmit={create}>
          <label>
            Nova playlist
            <input name="name" maxLength={120} required />
          </label>
          <button disabled={busy}>Criar playlist</button>
        </form>
      )}
    </details>
  );
}
