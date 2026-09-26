import type { Actor } from "@vtt/core";
import { useEffect, useRef, useState } from "react";
import { api } from "../../lib/api";
import { pluginRegistry } from "../../lib/plugins";
import { isManagerRole, useSession } from "../../store/session";
import { executeActorAction } from "../../lib/actorAction";
import type { CampaignMacro } from "../campaign-tools/MacroManager";

export function MacroHotbar({ center }: { center: () => { x: number; y: number } }) {
  const {
    campaignId,
    sceneId,
    selectedActorId,
    targetActorIds,
    patchState,
    setActors,
    setError,
    actors,
    role,
    user,
  } = useSession();
  const [macros, setMacros] = useState<CampaignMacro[]>([]);
  const [busy, setBusy] = useState<number | null>(null);
  const pending = useRef<{ sceneId: number; macroId: number; id: string } | null>(null);
  const [actionBusy, setActionBusy] = useState<string | null>(null);
  const pendingAction = useRef<{ key: string; id: string } | null>(null);
  const selectedActor = actors.find((actor) => actor.id === selectedActorId);
  const playableActor =
    selectedActor && (isManagerRole(role) || selectedActor.ownerUserId === user?.id)
      ? selectedActor
      : null;

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
    if (!sceneId || !campaignId || busy !== null || actionBusy !== null) return;
    if (pending.current?.sceneId !== sceneId || pending.current?.macroId !== macro.id) {
      pending.current = { sceneId, macroId: macro.id, id: crypto.randomUUID() };
    }
    setBusy(macro.id);
    try {
      const result = await api<{ state: ReturnType<typeof useSession.getState>["state"] }>(
        `/campaign-macros/${macro.id}/execute`,
        {
          method: "POST",
          body: JSON.stringify({
            sceneId,
            requestId: pending.current.id,
            actorId: selectedActorId,
            targetActorIds,
            point: center(),
          }),
        },
      );
      patchState(result.state);
      pending.current = null;
      const actors = await api<{ actors: Actor[] }>(`/campaigns/${campaignId}/actors`);
      setActors(actors.actors);
      await pluginRegistry.hooks.emit("macro:executed", { sceneId, macroId: macro.id });
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível executar a macro.");
    } finally {
      setBusy(null);
    }
  }

  async function playAction(actionId: string) {
    if (!sceneId || !playableActor || busy !== null || actionBusy !== null) return;
    const key = `${sceneId}:${playableActor.id}:${actionId}:${targetActorIds.join(",")}`;
    if (pendingAction.current?.key !== key)
      pendingAction.current = { key, id: crypto.randomUUID() };
    setActionBusy(actionId);
    try {
      await pluginRegistry.hooks.emit("action:before", {
        sceneId,
        actorId: playableActor.id,
        actionId,
      });
      const result = await executeActorAction(
        sceneId,
        playableActor,
        actionId,
        pendingAction.current.id,
        "normal",
        targetActorIds,
      );
      pendingAction.current = null;
      await pluginRegistry.hooks.emit("action:after", {
        sceneId,
        actorId: playableActor.id,
        actionId,
        messageId: result.message.id,
      });
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível executar a ação.");
    } finally {
      setActionBusy(null);
    }
  }

  if (!macros.length && !playableActor?.system.actions.length) return null;
  return (
    <div className="macro-hotbar" aria-label="Hotbar de macros e ações da ficha selecionada">
      {playableActor?.system.actions.slice(0, 6).map((action) => (
        <button
          key={`${playableActor.id}:${action.id}`}
          type="button"
          title={`${playableActor.name}: ${action.name}`}
          disabled={busy !== null || actionBusy !== null}
          onClick={() => void playAction(action.id)}
        >
          <small>
            {action.economy === "bonus" ? "B" : action.economy === "reaction" ? "R" : "A"}
          </small>
          {actionBusy === action.id ? "Executando…" : action.name}
        </button>
      ))}
      {macros
        .toSorted((a, b) => (a.hotbar_slot ?? 99) - (b.hotbar_slot ?? 99))
        .map((macro) => (
          <button
            key={macro.id}
            title={`${macro.hotbar_slot}. ${macro.name}`}
            disabled={busy !== null || actionBusy !== null}
            onClick={() => void execute(macro)}
          >
            <small>{macro.hotbar_slot}</small>
            {busy === macro.id ? "…" : macro.name}
          </button>
        ))}
    </div>
  );
}
