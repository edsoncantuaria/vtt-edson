import type { Drawing, Region, SceneLabel, SceneState, Tile } from "@vtt/core";
import { useState } from "react";
import { canvasObject, canvasPayload, type CanvasSelection } from "../../lib/canvasObjects";
import { updateScene } from "../../lib/scene";
import { Icon } from "../Icon";

export function CanvasObjectInspector({
  sceneId,
  state,
  selection,
  onClear,
  onError,
}: {
  sceneId: number;
  state: SceneState;
  selection: CanvasSelection;
  onClear: () => void;
  onError: (error: unknown) => void;
}) {
  const [busy, setBusy] = useState(false);
  const object = canvasObject(state, selection);
  if (!object) return null;

  async function save(patch: Record<string, unknown>) {
    if (!object || busy) return;
    setBusy(true);
    try {
      await updateScene(
        sceneId,
        `/canvas/${selection.kind}`,
        canvasPayload(selection.kind, { ...object, ...patch } as typeof object),
      );
    } catch (error) {
      onError(error);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (busy) return;
    setBusy(true);
    try {
      await updateScene(sceneId, `/canvas/${selection.kind}/${selection.id}`, undefined, "DELETE");
      onClear();
    } catch (error) {
      onError(error);
    } finally {
      setBusy(false);
    }
  }

  const title =
    selection.kind === "drawings"
      ? "Desenho"
      : selection.kind === "labels"
        ? "Texto"
        : selection.kind === "tiles"
          ? "Prop / tile"
          : "Região";

  return (
    <div className="canvas-object-inspector" role="region" aria-label={`Editar ${title}`}>
      <header>
        <strong>{title}</strong>
        <button className="icon-button" onClick={onClear} aria-label="Fechar propriedades">
          <Icon name="close" size={14} />
        </button>
      </header>
      {selection.kind === "drawings" && (
        <>
          <label>
            Traço
            <input
              type="color"
              defaultValue={(object as Drawing).stroke}
              disabled={busy}
              onChange={(event) => void save({ stroke: event.target.value })}
            />
          </label>
          <label>
            Espessura
            <input
              type="number"
              min={1}
              max={30}
              defaultValue={(object as Drawing).width}
              disabled={busy}
              onBlur={(event) =>
                void save({ width: Math.max(1, Math.min(30, Number(event.target.value))) })
              }
            />
          </label>
        </>
      )}
      {selection.kind === "labels" && (
        <>
          <label>
            Texto
            <input
              defaultValue={(object as SceneLabel).text}
              maxLength={500}
              disabled={busy}
              onBlur={(event) =>
                event.target.value.trim() && void save({ text: event.target.value.trim() })
              }
            />
          </label>
          <label>
            Tamanho
            <input
              type="number"
              min={8}
              max={96}
              defaultValue={(object as SceneLabel).fontSize}
              disabled={busy}
              onBlur={(event) =>
                void save({ fontSize: Math.max(8, Math.min(96, Number(event.target.value))) })
              }
            />
          </label>
        </>
      )}
      {selection.kind === "tiles" && (
        <>
          <div className="editor-grid">
            <label>
              Largura
              <input
                type="number"
                min={4}
                defaultValue={(object as Tile).w}
                disabled={busy}
                onBlur={(event) => void save({ w: Math.max(4, Number(event.target.value)) })}
              />
            </label>
            <label>
              Altura
              <input
                type="number"
                min={4}
                defaultValue={(object as Tile).h}
                disabled={busy}
                onBlur={(event) => void save({ h: Math.max(4, Number(event.target.value)) })}
              />
            </label>
          </div>
          <label>
            Rotação
            <input
              type="number"
              min={-360}
              max={360}
              defaultValue={(object as Tile).rotation}
              disabled={busy}
              onBlur={(event) => void save({ rotation: Number(event.target.value) })}
            />
          </label>
          <label>
            Opacidade
            <input
              type="range"
              min={0}
              max={1}
              step={0.05}
              defaultValue={(object as Tile).opacity}
              disabled={busy}
              onChange={(event) => void save({ opacity: Number(event.target.value) })}
            />
          </label>
        </>
      )}
      {selection.kind === "regions" && (
        <>
          <label>
            Nome
            <input
              defaultValue={(object as Region).name}
              maxLength={160}
              disabled={busy}
              onBlur={(event) =>
                event.target.value.trim() && void save({ name: event.target.value.trim() })
              }
            />
          </label>
          <div className="editor-grid">
            <label>
              Largura
              <input
                type="number"
                min={1}
                defaultValue={(object as Region).w}
                disabled={busy}
                onBlur={(event) => void save({ w: Math.max(1, Number(event.target.value)) })}
              />
            </label>
            <label>
              Altura
              <input
                type="number"
                min={1}
                defaultValue={(object as Region).h}
                disabled={busy}
                onBlur={(event) => void save({ h: Math.max(1, Number(event.target.value)) })}
              />
            </label>
          </div>
          <label>
            Comportamento
            <select
              defaultValue={(object as Region).behavior}
              disabled={busy}
              onChange={(event) => void save({ behavior: event.target.value })}
            >
              <option value="none">Marcador</option>
              <option value="difficult-terrain">Terreno difícil</option>
              <option value="danger">Perigo</option>
              <option value="trigger">Gatilho</option>
            </select>
          </label>
          <label>
            Nota / gatilho
            <textarea
              rows={2}
              maxLength={1000}
              defaultValue={(object as Region).note ?? ""}
              disabled={busy}
              onBlur={(event) => void save({ note: event.target.value || null })}
            />
          </label>
        </>
      )}
      <label className="check-label">
        <input
          type="checkbox"
          checked={Boolean((object as Drawing | SceneLabel | Tile | Region).hidden)}
          disabled={busy}
          onChange={(event) => void save({ hidden: event.target.checked })}
        />
        Oculto para jogadores
      </label>
      <button className="danger" disabled={busy} onClick={() => void remove()}>
        <Icon name="trash" size={14} /> Excluir
      </button>
    </div>
  );
}
