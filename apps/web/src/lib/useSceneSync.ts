import { useEffect, useState } from "react";
import { emptySceneState } from "@vtt/core";
import type { Actor, Combat, SceneState } from "@vtt/core";
import { api, apiWriteState, ApiError } from "./api";
import { createEcho } from "./echo";
import { sceneRequestVersion } from "./scene";
import { pluginRegistry } from "./plugins";
import { useSession } from "../store/session";

export function useSceneSync() {
  const sceneId = useSession((s) => s.sceneId);
  const campaignId = useSession((s) => s.campaignId);
  const [connection, setConnection] = useState<"connecting" | "live" | "polling" | "offline">(
    "connecting",
  );
  useEffect(() => {
    if (!sceneId || !campaignId) return;
    let active = true;
    let refreshing = false;
    let subscribed = false;
    let eventRevision = 0;
    const session = useSession.getState;
    async function refresh() {
      if (!active || refreshing || sceneRequestVersion().busy || apiWriteState().busy) return;
      refreshing = true;
      const version = sceneRequestVersion().revision;
      const apiVersion = apiWriteState().revision;
      const events = eventRevision;
      try {
        const [scene, actors, combat] = await Promise.all([
          api<{ scene: { state: SceneState; backgroundUrl: string | null } }>("/scenes/" + sceneId),
          api<{ actors: Actor[] }>("/campaigns/" + campaignId + "/actors"),
          api<{ combat: Combat | null }>("/scenes/" + sceneId + "/combat"),
        ]);
        if (!active || events !== eventRevision) return;
        if (version === sceneRequestVersion().revision && !sceneRequestVersion().busy) {
          session().patchState(scene.scene.state, scene.scene.backgroundUrl);
          await pluginRegistry.hooks.emit("scene:updated", { sceneId });
        }
        if (apiVersion === apiWriteState().revision && !apiWriteState().busy) {
          session().setActors(actors.actors);
          session().setCombat(combat.combat);
        }
        setConnection(subscribed ? "live" : "polling");
      } catch (error) {
        if (active && error instanceof ApiError && error.status === 403) {
          session().patchState(emptySceneState(), null);
          session().setCombat(null);
          session().setError("Esta cena está em preparação. Escolha uma cena publicada.");
        }
        if (active) setConnection("offline");
      } finally {
        refreshing = false;
      }
    }
    const echo = createEcho();
    const channel = echo.private("scene." + sceneId);
    channel
      .subscribed(() => {
        subscribed = true;
        if (active) setConnection("live");
        void refresh();
      })
      .error(() => {
        subscribed = false;
        if (active) setConnection("polling");
      })
      .listen(".SceneUpdated", () => {
        void refresh();
      })
      .listen(".CombatUpdated", () => {
        void refresh();
      });
    const disconnected = () => {
      subscribed = false;
      if (active) setConnection("polling");
    };
    echo.connector.pusher.connection.bind("disconnected", disconnected);
    echo.connector.pusher.connection.bind("unavailable", disconnected);
    void refresh();
    const timer = window.setInterval(() => {
      if (!document.hidden) void refresh();
    }, 5000);
    const visible = () => {
      if (!document.hidden) void refresh();
    };
    document.addEventListener("visibilitychange", visible);
    return () => {
      active = false;
      clearInterval(timer);
      document.removeEventListener("visibilitychange", visible);
      echo.leave("scene." + sceneId);
      echo.disconnect();
    };
  }, [sceneId, campaignId]);
  return connection;
}
