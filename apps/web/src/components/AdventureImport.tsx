import { useState } from "react";
import { useSession, type SceneSummary } from "../store/session";
import { api } from "../lib/api";
import type { CatalogEntry } from "../lib/catalog";
type MapChoice = {
  index: number;
  title: string;
  player: boolean;
  grid: { type?: string };
  pins?: unknown[];
  encounters?: unknown[];
};
type EncounterHint = {
  label: string;
  area?: string;
  creature: { name: string; source?: string };
  quantity: number;
  confidence: "high" | "medium";
};
export function AdventureImport({
  entry,
  campaignId,
}: {
  entry: CatalogEntry;
  campaignId: number;
}) {
  const [imported, setImported] = useState<
    {
      id: number;
      name: string;
      existing: boolean;
      journalId?: number | null;
      encounterDraftId?: number | null;
      pins?: number;
      encounters?: number;
    }[]
  >([]);
  const [maps, setMaps] = useState<MapChoice[] | null>(null),
    [encounterHints, setEncounterHints] = useState<EncounterHint[]>([]),
    [selected, setSelected] = useState<number[]>([]),
    [busy, setBusy] = useState(false),
    [message, setMessage] = useState("");
  async function preview() {
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{ maps: MapChoice[]; encounterHints?: EncounterHint[] }>(
        `/campaigns/${campaignId}/adventures/${entry.id}/preview`,
      );
      setMaps(result.maps);
      setEncounterHints(result.encounterHints ?? []);
      setSelected(
        result.maps
          .filter((map) => map.player)
          .slice(0, 10)
          .map((map) => map.index),
      );
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Falha ao ler mapas.");
    } finally {
      setBusy(false);
    }
  }
  async function importMaps() {
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{ scenes: { id: number; name: string; existing: boolean }[] }>(
        `/campaigns/${campaignId}/adventures/${entry.id}/import`,
        { method: "POST", body: JSON.stringify({ maps: selected }) },
      );
      setImported(result.scenes);
      setMessage(
        `${result.scenes.filter((scene) => !scene.existing).length} cenas criadas em preparação. Cenas já importadas foram preservadas. Abra Preparar cena para revisar e publicar.`,
      );
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Falha ao importar.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <section>
      <button disabled={busy} onClick={() => void preview()}>
        Preparar mapas deste capítulo
      </button>
      {maps && (
        <>
          <p>
            Escolha até 10 mapas. Pins, encontros explícitos e pistas de criaturas extraídas do
            texto serão guardados na nota vinculada à cena. Quando as criaturas resolvem no
            compêndio, um rascunho de encontro jogável é criado automaticamente. Versões do mestre
            podem conter segredos; grades hexagonais ainda não são aplicadas.
          </p>
          {encounterHints.length > 0 && (
            <details>
              <summary>{encounterHints.length} pistas de encontro encontradas no texto</summary>
              {encounterHints.slice(0, 30).map((hint, index) => (
                <p key={`${hint.label}-${hint.creature.name}-${index}`}>
                  <b>{hint.label}</b> · {hint.quantity}× {hint.creature.name}
                  {hint.creature.source ? ` (${hint.creature.source})` : ""} · confiança{" "}
                  {hint.confidence === "high" ? "alta" : "média"}
                </p>
              ))}
            </details>
          )}
          {maps.length === 0 && <p>Este capítulo não contém mapas identificados.</p>}
          {maps.map((map) => (
            <label className="check-label" key={map.index}>
              <input
                type="checkbox"
                checked={selected.includes(map.index)}
                disabled={busy || (!selected.includes(map.index) && selected.length >= 10)}
                onChange={(event) =>
                  setSelected((items) =>
                    event.target.checked
                      ? [...items, map.index]
                      : items.filter((i) => i !== map.index),
                  )
                }
              />
              {map.title} · {map.player ? "jogadores" : "mestre"} ·{" "}
              {map.grid.type ?? "grade não informada"} · {map.pins?.length ?? 0} pins ·{" "}
              {map.encounters?.length ?? 0} encontros explícitos
            </label>
          ))}
          <button disabled={busy || !selected.length} onClick={() => void importMaps()}>
            {busy ? "Importando…" : "Criar cenas privadas e notas"}
          </button>
        </>
      )}
      {message && <p role="status">{message}</p>}
      {imported.map((scene) => (
        <p key={scene.id}>
          {scene.name} · {scene.existing ? "cópia preservada" : "privada"}
          {!scene.existing && ` · ${scene.pins ?? 0} pins · ${scene.encounters ?? 0} encontros`}
          {scene.encounterDraftId && " · encontro jogável criado"}{" "}
          {scene.journalId && "· nota vinculada"}{" "}
          <button
            disabled={busy}
            onClick={() => {
              setBusy(true);
              void api<{ scene: SceneSummary }>(`/scenes/${scene.id}`)
                .then((result) => {
                  useSession.getState().enterScene(result.scene);
                  useSession.getState().setPanel("scene");
                })
                .catch((error) =>
                  setMessage(
                    error instanceof Error ? error.message : "Não foi possível abrir a cena.",
                  ),
                )
                .finally(() => setBusy(false));
            }}
          >
            Abrir preparação de cenas
          </button>
          {scene.journalId && (
            <button disabled={busy} onClick={() => useSession.getState().setPanel("journal")}>
              Abrir nota
            </button>
          )}
          {scene.encounterDraftId && (
            <button disabled={busy} onClick={() => useSession.getState().setPanel("scene")}>
              Abrir encontros
            </button>
          )}
        </p>
      ))}
    </section>
  );
}
