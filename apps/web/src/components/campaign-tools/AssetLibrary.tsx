import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "../../lib/api";
import { updateScene } from "../../lib/scene";
import { isManagerRole, useSession } from "../../store/session";
import { Icon } from "../Icon";

type CampaignAsset = {
  id: number;
  name: string;
  kind: "image" | "audio" | "document";
  mime: string;
  size_bytes: number;
  url: string;
};

export function AssetLibrary({ center }: { center: () => { x: number; y: number } }) {
  const { campaignId, sceneId, role, setError } = useSession();
  const [assets, setAssets] = useState<CampaignAsset[]>([]);
  const [busy, setBusy] = useState(false);
  const upload = useRef<HTMLInputElement>(null);

  const refresh = useCallback(async () => {
    if (!campaignId) return;
    try {
      const result = await api<{ assets: CampaignAsset[] }>(`/campaigns/${campaignId}/assets`);
      setAssets(result.assets);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível carregar os assets.");
    }
  }, [campaignId, setError]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function uploadFile(file?: File) {
    if (!file || !campaignId || busy) return;
    setBusy(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      await api(`/campaigns/${campaignId}/assets`, { method: "POST", formData });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível enviar o asset.");
    } finally {
      setBusy(false);
    }
  }

  async function place(asset: CampaignAsset) {
    if (!sceneId || !isManagerRole(role)) return;
    const point = center();
    try {
      if (asset.kind === "image") {
        await updateScene(sceneId, "/canvas/tiles", {
          assetId: asset.id,
          x: Math.max(0, point.x - 120),
          y: Math.max(0, point.y - 120),
          w: 240,
          h: 240,
          opacity: 1,
          rotation: 0,
        });
      } else if (asset.kind === "audio") {
        await updateScene(sceneId, "/audio", { assetId: asset.id, volume: 0.5, loop: true });
      }
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Não foi possível usar o asset nesta cena.",
      );
    }
  }

  async function remove(asset: CampaignAsset) {
    if (busy) return;
    setBusy(true);
    try {
      await api(`/campaign-assets/${asset.id}`, { method: "DELETE" });
      setAssets((current) => current.filter((item) => item.id !== asset.id));
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível excluir o asset.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section>
      <div className="characters-tools">
        <h3>Biblioteca de assets</h3>
        {isManagerRole(role) && (
          <button onClick={() => upload.current?.click()} disabled={busy}>
            <Icon name="upload" size={15} />
            Enviar arquivo
          </button>
        )}
      </div>
      <p className="panel-hint">
        Imagens, áudio e documentos são deduplicados por conteúdo e reutilizáveis na campanha.
      </p>
      <input
        ref={upload}
        hidden
        type="file"
        accept="image/*,audio/*,application/pdf,text/plain"
        onChange={(event) => {
          void uploadFile(event.target.files?.[0]);
          event.target.value = "";
        }}
      />
      <div className="sheet-items">
        {assets.map((asset) => (
          <article key={asset.id}>
            <div>
              <h4>{asset.name}</h4>
              <small>
                {asset.kind} · {(asset.size_bytes / 1024 / 1024).toFixed(2)} MB
              </small>
            </div>
            {asset.kind === "image" && (
              <img
                src={asset.url}
                alt=""
                loading="lazy"
                style={{ maxWidth: "100%", maxHeight: 140 }}
              />
            )}
            <div className="item-actions">
              {isManagerRole(role) && asset.kind === "image" && (
                <button onClick={() => void place(asset)}>Colocar como prop</button>
              )}
              {isManagerRole(role) && asset.kind === "audio" && (
                <button onClick={() => void place(asset)}>Usar como ambiente</button>
              )}
              <a href={asset.url} target="_blank" rel="noreferrer">
                Abrir
              </a>
              {isManagerRole(role) && (
                <button className="danger" disabled={busy} onClick={() => void remove(asset)}>
                  Excluir
                </button>
              )}
            </div>
          </article>
        ))}
        {!assets.length && <p className="panel-hint">Nenhum asset salvo nesta campanha.</p>}
      </div>
    </section>
  );
}
