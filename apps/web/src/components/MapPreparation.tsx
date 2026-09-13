import { useState } from "react";
import { api } from "../lib/api";
import type { CatalogEntry, CatalogResults } from "../lib/catalog";
import { useSession } from "../store/session";
import { FiveToolsEntries } from "./FiveToolsEntries";
import type { SceneState } from "@vtt/core";

export function MapPreparation() {
  const { state, sceneId, campaignId, setError, patchState, setPanel } = useSession();
  const [chapter, setChapter] = useState<CatalogEntry | null>(null);
  const [comparison, setComparison] = useState("");
  const [busy, setBusy] = useState(false);
  const preparation = state.preparation;
  if (!preparation) return null;
  async function source() {
    if (!preparation) return;
    setBusy(true);
    try {
      const [entry, preview] = await Promise.all([
        api<CatalogResults>(
          `/catalog/adventures?campaignId=${campaignId}&id=${preparation.entryId}`,
        ),
        api<{ sourceHash: string }>(
          `/campaigns/${campaignId}/adventures/${preparation.entryId}/preview`,
        ),
      ]);
      setChapter(entry.data[0] ?? null);
      setComparison(
        preview.sourceHash === preparation.sourceHash
          ? "O catálogo corresponde à versão importada."
          : "O capítulo mudou no catálogo. Sua cena e suas notas foram preservadas; compare o texto abaixo antes de ajustar a preparação.",
      );
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível consultar a origem.");
    } finally {
      setBusy(false);
    }
  }
  async function review(reviewed: boolean) {
    setBusy(true);
    try {
      const response = await api<{ scene: { state: SceneState } }>(`/scenes/${sceneId}`, {
        method: "PATCH",
        body: JSON.stringify({ preparationReviewed: reviewed }),
      });
      patchState(response.scene.state);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível salvar a revisão.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <section>
      <h3>Revisar mapa importado</h3>
      <p>
        {preparation.chapter} · {preparation.source}
      </p>
      <p>
        Confira a escala da grade, segredos na imagem, paredes/portas, iluminação e criaturas antes
        de publicar.
      </p>
      <button disabled={busy} onClick={() => void source()}>
        Abrir capítulo e comparar versão
      </button>
      <button onClick={() => setPanel("journal")}>Abrir notas desta cena</button>
      <label className="check-label">
        <input
          type="checkbox"
          disabled={busy}
          checked={preparation.reviewed}
          onChange={(e) => void review(e.target.checked)}
        />
        Revisei a escala, os segredos e a preparação deste mapa
      </label>
      {comparison && <p role="status">{comparison}</p>}
      {chapter && (
        <details open>
          <summary>{chapter.name} · texto atual do catálogo</summary>
          <FiveToolsEntries value={chapter.data.raw} />
        </details>
      )}
    </section>
  );
}
