import { useCallback, useEffect, useState } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";

type Loot = {
  id: number;
  name: string;
  status: "draft" | "applied";
  items: { name: string; quantity: number }[];
  currency: Record<string, number>;
  applied_actor_id?: number | null;
};

export function LootManager({
  seed,
}: {
  seed?: { rollTableRollId?: number; name: string; data: Record<string, unknown> } | null;
}) {
  const { campaignId, actors, upsertActor } = useSession();
  const [loot, setLoot] = useState<Loot[]>([]);
  const [target, setTarget] = useState("");
  const [message, setMessage] = useState("");
  const refresh = useCallback(async () => {
    if (campaignId) setLoot((await api<{ loot: Loot[] }>(`/campaigns/${campaignId}/loot`)).loot);
  }, [campaignId]);
  useEffect(() => {
    void refresh().catch((e) => setMessage(e.message));
  }, [refresh]);
  useEffect(() => {
    const changed = () => void refresh().catch((e) => setMessage(e.message));
    window.addEventListener("vtt:loot-changed", changed);
    return () => window.removeEventListener("vtt:loot-changed", changed);
  }, [refresh]);

  async function saveSeed() {
    if (!campaignId || !seed) return;
    const items = Array.isArray(seed.data.items) ? seed.data.items : [];
    const currency =
      typeof seed.data.currency === "object" && seed.data.currency ? seed.data.currency : {};
    try {
      await api(`/campaigns/${campaignId}/loot`, {
        method: "POST",
        body: JSON.stringify({
          name: seed.name,
          rollTableRollId: seed.rollTableRollId,
          items,
          currency,
          metadata: { source: "roll-table" },
        }),
      });
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível salvar o tesouro.");
    }
  }

  async function apply(item: Loot) {
    if (!target) return;
    try {
      const result = await api<{ actor: Parameters<typeof upsertActor>[0] }>(
        `/loot/${item.id}/apply`,
        { method: "POST", body: JSON.stringify({ actorId: Number(target) }) },
      );
      upsertActor(result.actor);
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível aplicar o tesouro.");
    }
  }

  return (
    <section className="loot-manager">
      <h3>Tesouro</h3>
      {seed && <button onClick={() => void saveSeed()}>Salvar “{seed.name}” como tesouro</button>}
      <label>
        Aplicar em
        <select value={target} onChange={(e) => setTarget(e.target.value)}>
          <option value="">Escolha uma ficha</option>
          {actors.map((actor) => (
            <option key={actor.id} value={actor.id}>
              {actor.name}
            </option>
          ))}
        </select>
      </label>
      {loot.map((item) => (
        <article key={item.id}>
          <b>{item.name}</b>
          <p>
            {item.items?.map((entry) => `${entry.quantity ?? 1}× ${entry.name}`).join(", ") ||
              "Sem itens"}
          </p>
          <small>
            {Object.entries(item.currency ?? {})
              .filter(([, value]) => value)
              .map(([coin, value]) => `${value} ${coin}`)
              .join(" · ") || "Sem moedas"}
          </small>
          {item.status === "draft" ? (
            <button disabled={!target} onClick={() => void apply(item)}>
              Aplicar manualmente
            </button>
          ) : (
            <span>Aplicado</span>
          )}
        </article>
      ))}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
