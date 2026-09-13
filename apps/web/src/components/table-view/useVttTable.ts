import type { AreaTemplate, Role } from "@vtt/core";
import { useEffect, useRef, useState } from "react";
import { updateScene } from "../../lib/scene";
import { VttTable } from "../../pixi/VttTable";
import { useSession } from "../../store/session";

type ReportError = (error: unknown) => void;

export function useVttTable({
  sceneId,
  role,
  userId,
  tokenActorId,
  report,
  onActorPanelOpen,
}: {
  sceneId: number | null;
  role: Role | null;
  userId: number | undefined;
  tokenActorId: string;
  report: ReportError;
  onActorPanelOpen: () => void;
}) {
  const host = useRef<HTMLDivElement>(null);
  const table = useRef<VttTable | null>(null);
  const tokenActorRef = useRef(tokenActorId);
  tokenActorRef.current = tokenActorId;

  const state = useSession((session) => session.state);
  const backgroundUrl = useSession((session) => session.backgroundUrl);
  const actors = useSession((session) => session.actors);
  const setSelectedActorId = useSession((session) => session.setSelectedActorId);
  const setPanel = useSession((session) => session.setPanel);
  const setMapTargets = useSession((session) => session.setTargetActorIds);

  const [zoom, setZoom] = useState(1);
  const [selectedTokenIds, setSelectedTokenIds] = useState<string[]>([]);
  const [lastTemplate, setLastTemplate] = useState<AreaTemplate | null>(null);
  const [preparing, setPreparing] = useState(true);
  const [effect, setEffect] = useState<{ url: string; label: string } | null>(null);

  useEffect(() => {
    if (!host.current || !sceneId || !role || !userId) return;

    setSelectedTokenIds([]);
    setLastTemplate(null);
    setPreparing(true);
    let active = true;
    const instance = new VttTable({
      host: host.current,
      role,
      userId,
      getTool: () => useSession.getState().tool,
      getActors: () => useSession.getState().actors,
      callbacks: {
        onZoom: (nextZoom) => {
          if (active) setZoom(nextZoom);
        },
        onActorOpen: (actorId) => {
          setSelectedActorId(actorId);
          setPanel("actors");
          onActorPanelOpen();
        },
        onTokenSelection: (tokenIds) => {
          if (!active) return;
          setSelectedTokenIds(tokenIds);
          const current = useSession.getState();
          setMapTargets(
            tokenIds.flatMap((tokenId) => {
              const actorId = current.state.tokens.find((token) => token.id === tokenId)?.actorId;
              return typeof actorId === "number" ? [actorId] : [];
            }),
          );
        },
        onAreaTemplate: (template) => {
          if (!active) return;
          setLastTemplate(template);
          setMapTargets(template.actorIds);
        },
        onTokenMove: (tokenId, x, y) => {
          void updateScene(sceneId, "/tokens", { id: tokenId, x, y }).catch((error) => {
            report(error);
            const current = useSession.getState();
            if (active) void instance.render(current.state, current.backgroundUrl).catch(report);
          });
        },
        onTokenCreate: (x, y) => {
          const current = useSession.getState();
          const actor = current.actors.find((item) => item.id === Number(tokenActorRef.current));
          void updateScene(sceneId, "/tokens", {
            x,
            y,
            name: actor?.name ?? "Novo token",
            actorId: actor?.id,
            ownerUserId: actor?.ownerUserId ?? userId,
          }).catch(report);
        },
        onWallCreate: (x1, y1, x2, y2) => {
          void updateScene(sceneId, "/walls", { x1, y1, x2, y2 }).catch(report);
        },
        onDoorCreate: (x1, y1, x2, y2) => {
          void updateScene(sceneId, "/doors", { x1, y1, x2, y2, open: false }).catch(report);
        },
        onLightCreate: (x, y) => {
          void updateScene(sceneId, "/lights", { x, y, radius: 180 }).catch(report);
        },
        onFogPaint: (x, y, w, h) => {
          void updateScene(sceneId, "/fog", { x, y, w, h, mode: "reveal" }).catch(report);
        },
      },
    });

    void instance
      .init()
      .then(async () => {
        if (!active || !instance.ready) return;
        table.current = instance;
        const current = useSession.getState();
        await instance.render(current.state, current.backgroundUrl);
        if (!active) return;
        instance.fit();
        setPreparing(false);
      })
      .catch((error) => {
        if (!active) return;
        report(error);
        setPreparing(false);
      });

    return () => {
      active = false;
      instance.destroy();
      if (table.current === instance) table.current = null;
    };
  }, [
    sceneId,
    role,
    userId,
    setMapTargets,
    setPanel,
    setSelectedActorId,
    onActorPanelOpen,
    report,
  ]);

  useEffect(() => {
    if (table.current?.ready) void table.current.render(state, backgroundUrl).catch(report);
  }, [state, backgroundUrl, actors, report]);

  useEffect(() => {
    const lastMessage = state.chat.at(-1);
    if (!lastMessage?.effectUrl) return;
    setEffect({ url: lastMessage.effectUrl, label: lastMessage.label ?? "Efeito de ação" });
    const timer = window.setTimeout(() => setEffect(null), 6000);
    return () => window.clearTimeout(timer);
  }, [state.chat]);

  return {
    host,
    table,
    zoom,
    selectedTokenIds,
    lastTemplate,
    preparing,
    effect,
    dismissEffect: () => setEffect(null),
  };
}
