import { useCallback, useEffect, useState } from "react";
import { api } from "../../lib/api";
import type { CatalogEntry, CatalogKind, CatalogResults } from "../../lib/catalog";
import { isManagerRole, useSession } from "../../store/session";

type Subsystem = {
  id: number;
  kind: CatalogKind;
  name: string;
  state: Record<string, unknown>;
  metadata: Record<string, unknown>;
};

const KINDS: Array<{ id: CatalogKind; label: string }> = [
  { id: "bastions", label: "Bastiões" },
  { id: "vehicles", label: "Veículos" },
  { id: "decks", label: "Baralhos" },
  { id: "recipes", label: "Receitas" },
  { id: "psionics", label: "Psiônicos" },
  { id: "rewards", label: "Recompensas" },
  { id: "deities", label: "Divindades" },
  { id: "languages", label: "Idiomas" },
  { id: "hazards", label: "Perigos" },
  { id: "objects", label: "Objetos" },
  { id: "cults", label: "Cultos e dádivas" },
];

export function SubsystemManager() {
  const { campaignId, ruleset, role, actors, selectedActorId, setError } = useSession();
  const [subsystems, setSubsystems] = useState<Subsystem[]>([]);
  const [kind, setKind] = useState<CatalogKind>("decks");
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<CatalogEntry[]>([]);

  const refresh = useCallback(async () => {
    if (!campaignId) return;
    try {
      const result = await api<{ subsystems: Subsystem[] }>(`/campaigns/${campaignId}/subsystems`);
      setSubsystems(result.subsystems);
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Não foi possível carregar os subsistemas.",
      );
    }
  }, [campaignId, setError]);

  useEffect(() => {
    void refresh();
  }, [refresh]);
  useEffect(() => {
    if (!campaignId || !isManagerRole(role)) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        campaignId: String(campaignId),
        edition: ruleset,
        query,
        perPage: "10",
      });
      void api<CatalogResults>(`/catalog/${kind}?${params}`, { signal: controller.signal })
        .then((result) => setResults(result.data))
        .catch((error) => {
          if (!controller.signal.aborted) setError(error.message);
        });
    }, 200);
    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [campaignId, kind, query, role, ruleset, setError]);

  async function add(entry: CatalogEntry) {
    if (!campaignId) return;
    try {
      await api(`/campaigns/${campaignId}/subsystems`, {
        method: "POST",
        body: JSON.stringify({ catalogEntryId: entry.id }),
      });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível ativar o subsistema.");
    }
  }

  async function action(subsystem: Subsystem, actionName: string, extra: object = {}) {
    try {
      await api(`/campaign-subsystems/${subsystem.id}/actions`, {
        method: "POST",
        body: JSON.stringify({ action: actionName, ...extra }),
      });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível executar a ação.");
    }
  }

  async function remove(id: number) {
    try {
      await api(`/campaign-subsystems/${id}`, { method: "DELETE" });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível remover o subsistema.");
    }
  }

  return (
    <section>
      <h3>Subsistemas de campanha</h3>
      <p className="panel-hint">
        O conteúdo opcional do 5e.tools vira estado jogável: baralhos, veículos, bastiões, receitas
        e referências aplicáveis à ficha.
      </p>
      {isManagerRole(role) && (
        <details>
          <summary>Adicionar do compêndio</summary>
          <div className="editor-grid">
            <label>
              Tipo
              <select value={kind} onChange={(event) => setKind(event.target.value as CatalogKind)}>
                {KINDS.map((item) => (
                  <option value={item.id} key={item.id}>
                    {item.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Buscar
              <input value={query} onChange={(event) => setQuery(event.target.value)} />
            </label>
          </div>
          {results.map((entry) => (
            <button key={entry.id} onClick={() => void add(entry)}>
              + {entry.name} · {entry.source}
            </button>
          ))}
        </details>
      )}
      <div className="sheet-items">
        {subsystems.map((subsystem) => {
          const state = subsystem.state;
          return (
            <article key={subsystem.id}>
              <div>
                <h4>{subsystem.name}</h4>
                <small>
                  {KINDS.find((item) => item.id === subsystem.kind)?.label ?? subsystem.kind}
                </small>
              </div>
              {subsystem.kind === "decks" && (
                <p>
                  {String((state.draw as unknown[] | undefined)?.length ?? 0)} cartas · última:{" "}
                  {String(state.lastDraw ?? "—")}
                </p>
              )}
              {subsystem.kind === "vehicles" && (
                <p>
                  PV {String(state.hp ?? 0)}/{String(state.maxHp ?? 0)} · CA{" "}
                  {String(state.ac ?? "—")}
                </p>
              )}
              {subsystem.kind === "bastions" && <p>Turno de bastião: {String(state.turn ?? 0)}</p>}
              {subsystem.kind === "recipes" && (
                <p>
                  Progresso: {String(state.progress ?? 0)}/{String(state.required ?? 1)}{" "}
                  {state.completed ? "· concluída" : ""}
                </p>
              )}
              <div className="item-actions">
                {subsystem.kind === "decks" && (
                  <>
                    <button onClick={() => void action(subsystem, "deck.draw")}>Comprar</button>
                    {isManagerRole(role) && (
                      <button onClick={() => void action(subsystem, "deck.shuffle")}>
                        Embaralhar
                      </button>
                    )}
                  </>
                )}
                {isManagerRole(role) && subsystem.kind === "vehicles" && (
                  <>
                    <button
                      onClick={() =>
                        void action(subsystem, "vehicle.damage", {
                          value: Number(window.prompt("Dano", "10") ?? 0),
                        })
                      }
                    >
                      Dano
                    </button>
                    <button
                      onClick={() =>
                        void action(subsystem, "vehicle.heal", {
                          value: Number(window.prompt("Reparo", "10") ?? 0),
                        })
                      }
                    >
                      Reparar
                    </button>
                  </>
                )}
                {isManagerRole(role) && subsystem.kind === "bastions" && (
                  <button onClick={() => void action(subsystem, "bastion.advance")}>
                    Avançar turno
                  </button>
                )}
                {isManagerRole(role) && subsystem.kind === "recipes" && (
                  <button onClick={() => void action(subsystem, "recipe.progress", { value: 1 })}>
                    + progresso
                  </button>
                )}
                {isManagerRole(role) &&
                  !["decks", "vehicles", "bastions", "recipes"].includes(subsystem.kind) &&
                  selectedActorId && (
                    <button
                      onClick={() =>
                        void action(subsystem, "apply-to-actor", { actorId: selectedActorId })
                      }
                    >
                      Aplicar a{" "}
                      {actors.find((actor) => actor.id === selectedActorId)?.name ?? "ficha"}
                    </button>
                  )}
                {isManagerRole(role) && (
                  <button className="danger" onClick={() => void remove(subsystem.id)}>
                    Remover
                  </button>
                )}
              </div>
            </article>
          );
        })}
      </div>
    </section>
  );
}
