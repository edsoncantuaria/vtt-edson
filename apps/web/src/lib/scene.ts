import type { Actor, ChatMessage, RollRecord, SceneState } from "@vtt/core";
import { api, getToken } from "./api";
import { useSession } from "../store/session";

let pending: Promise<unknown> = Promise.resolve();
let writes = 0;
let revision = 0;
export function sceneRequestVersion() {
  return { revision, busy: writes > 0 };
}
/** Serialize local scene writes; Laravel remains the source of truth. */
export function updateScene(
  sceneId: number,
  suffix: string,
  data?: object | FormData,
  method = "POST",
) {
  const token = getToken();
  writes++;
  revision++;
  const request = pending
    .catch(() => {})
    .then(async () => {
      if (useSession.getState().sceneId !== sceneId || getToken() !== token)
        throw new Error("A mesa foi fechada antes de enviar a ação.");
      const result = await api<{
        state: SceneState;
        actor?: Actor;
        roll?: RollRecord & { replayed?: boolean };
        backgroundUrl?: string | null;
      }>("/scenes/" + sceneId + suffix, {
        method,
        ...(data instanceof FormData
          ? { formData: data }
          : data
            ? { body: JSON.stringify(data) }
            : {}),
      });
      if (useSession.getState().sceneId === sceneId && getToken() === token) {
        useSession.getState().patchState(result.state, result.backgroundUrl);
        if (result.actor) useSession.getState().upsertActor(result.actor);
        // Reserved rolls never enter public scene chat. Display only the sender's
        // server-confirmed result locally, without broadcasting it to other clients.
        if (result.roll && result.roll.visibility !== "public" && !result.roll.replayed) {
          const user = useSession.getState().user;
          if (user) {
            const roll = result.roll;
            const message: ChatMessage = {
              id: roll.id,
              rollId: roll.id,
              type: "roll",
              userId: user.id,
              userName: user.name,
              createdAt: roll.createdAt,
              label: roll.label,
              formula: roll.formula,
              total: roll.total,
              detail: roll.detail,
              critical: roll.critical,
              fumble: roll.fumble,
            };
            window.dispatchEvent(
              new CustomEvent("vtt:local-roll-feedback", { detail: { sceneId, message } }),
            );
          }
        }
      }
      return result;
    })
    .finally(() => {
      writes--;
      revision++;
    });
  pending = request;
  return request;
}
