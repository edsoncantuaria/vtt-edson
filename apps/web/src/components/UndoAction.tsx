import { useState } from "react";
import type { Actor } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";

export function UndoAction({ messageId, actorId }: { messageId: string; actorId: number }) {
  const { actors, user, role, sceneId, upsertActor, setError } = useSession();
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const actor = actors.find((a) => a.id === actorId);
  if (!actor || (role !== "gm" && actor.ownerUserId !== user?.id)) return null;
  async function undo() {
    setBusy(true);
    try {
      const result = await api<{ actor: Actor }>(`/scenes/${sceneId}/actions/${messageId}/undo`, {
        method: "POST",
        body: JSON.stringify({ actorId }),
      });
      upsertActor(result.actor);
      setDone(true);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível desfazer a ação.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <details className="action-resolution">
      <summary>Desfazer uso da ação</summary>
      <p>
        Restaura espaços e concentração anteriores. Desfaça primeiro o dano de todos os alvos. O
        histórico das rolagens será preservado.
      </p>
      <button disabled={busy || done} onClick={() => void undo()}>
        {done ? "Ação desfeita" : busy ? "Desfazendo…" : "Confirmar desfazer ação"}
      </button>
    </details>
  );
}
