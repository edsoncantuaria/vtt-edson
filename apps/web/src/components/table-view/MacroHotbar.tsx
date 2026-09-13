import type { Actor } from "@vtt/core";
import { useEffect, useState } from "react";
import { api } from "../../lib/api";
import { pluginRegistry } from "../../lib/plugins";
import { useSession } from "../../store/session";
import type { CampaignMacro } from "../campaign-tools/MacroManager";

export function MacroHotbar({ center }: { center: () => { x: number; y: number } }) {
  const { campaignId, sceneId, selectedActorId, targetActorIds, patchState, setActors, setError } =
    useSession();
  const [macros, setMacros] = useState<CampaignMacro[]>([]);
  const [busy, setBusy] = useState<number | null>(null);

  useEffect(() => {
    if (!campaignId) return;
    let active = true;
    const refresh = () =>
      void api<{ macros: CampaignMacro[] }>(`/campaigns/${campaignId}/macros`)
        .then((result) => {
          if (active) setMacros(result.macros.filter((macro) => macro.hotbar_slot != null));
        })
        .catch((error) => {
          if (active)
            setError(
              error instanceof Error ? error.message : "Não foi possível carregar a hotbar.",
            );
        });
    refresh();
    window.addEventListener("vtt:macros-changed", refresh);
    return () => {
      active = false;
      window.removeEventListener("vtt:macros-changed", refresh);
    };
  }, [campaignId, setError]);

  async function execute(macro: CampaignMacro) {
    if (!sceneId || !campaignId || busy !== null) return;
    setBusy(macro.id);
    try {
      const result = await api<{ state: ReturnType<typeof useSession.getState>["state"] }>(
        `/campaign-macros/${macro.id}/execute`,
        {
          method: "POST",
          body: JSON.stringify({
            sceneId,
            actorId: selectedActorId,
            targetActorIds,
            point: center(),
          }),
        },
      );
      patchState(result.state);
      const actors = await api<{ actors: Actor[] }>(`/campaigns/${campaignId}/actors`);
      setActors(actors.actors);
      await pluginRegistry.hooks.emit("macro:executed", { sceneId, macroId: macro.id });
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível executar a macro.");
    } finally {
      setBusy(null);
    }
  }

  if (!macros.length) return null;
  return (
    <div className="macro-hotbar" aria-label="Hotbar de macros">
      {macros
        .toSorted((a, b) => (a.hotbar_slot ?? 99) - (b.hotbar_slot ?? 99))
        .map((macro) => (
          <button
            key={macro.id}
            title={`${macro.hotbar_slot}. ${macro.name}`}
            disabled={busy !== null}
            onClick={() => void execute(macro)}
          >
            <small>{macro.hotbar_slot}</small>
            {busy === macro.id ? "…" : macro.name}
          </button>
        ))}
    </div>
  );
}
