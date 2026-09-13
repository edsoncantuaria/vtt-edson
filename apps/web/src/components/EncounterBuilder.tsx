import { useCallback, useEffect, useRef, useState } from "react";
import type { Actor, Combat, SceneState } from "@vtt/core";
import { api } from "../lib/api";
import type { CatalogEntry, CatalogResults } from "../lib/catalog";
import { useSession } from "../store/session";

type CreatureChoice = { catalogEntryId: number; quantity: number; name: string };
type Draft = {
  id: number;
  name: string;
  creatures: Array<CreatureChoice & { source: string; cr: string | null; xp: number }>;
  difficulty: {
    total: number;
    adjusted: number;
    count: number;
    multiplier: number;
    rating: string;
    thresholds: Record<string, number>;
    ruleset?: string;
  };
  party: { level: number }[];
};
type SceneChoice = { id: number; name: string; published: boolean };
type InstantiateResult = {
  actors: Actor[];
  tokenIds: string[];
  combat: Combat | null;
  state: SceneState;
  existing: boolean;
};

export function EncounterBuilder() {
  const { campaignId, ruleset, sceneId, patchState, upsertActor, setCombat } = useSession();
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [scenes, setScenes] = useState<SceneChoice[]>([]);
  const [results, setResults] = useState<CatalogEntry[]>([]);
  const [query, setQuery] = useState("");
  const [name, setName] = useState("Novo encontro");
  const [party, setParty] = useState("1,1,1,1");
  const [choices, setChoices] = useState<CreatureChoice[]>([]);
  const [message, setMessage] = useState("");
  const [targetSceneId, setTargetSceneId] = useState<number | null>(sceneId);
  const [startX, setStartX] = useState(140);
  const [startY, setStartY] = useState(140);
  const [hidden, setHidden] = useState(false);
  const [combatMode, setCombatMode] = useState<"none" | "add" | "start">("none");
  const [busyDraft, setBusyDraft] = useState<number | null>(null);
  const instantiateRequests = useRef(new Map<number, string>());

  const refresh = useCallback(async () => {
    if (!campaignId) return;
    setDrafts((await api<{ drafts: Draft[] }>(`/campaigns/${campaignId}/encounter-drafts`)).drafts);
  }, [campaignId]);
  useEffect(() => {
    if (!campaignId) return;
    void Promise.all([
      refresh(),
      api<{ scenes: SceneChoice[] }>(`/campaigns/${campaignId}/scenes`).then((response) => {
        setScenes(response.scenes);
        setTargetSceneId((current) => current ?? sceneId ?? response.scenes[0]?.id ?? null);
      }),
    ]).catch((e) => setMessage(e.message));
  }, [campaignId, sceneId, refresh]);
  useEffect(() => {
    const changed = () =>
      void refresh().catch((error) =>
        setMessage(
          error instanceof Error ? error.message : "Não foi possível atualizar os encontros.",
        ),
      );
    window.addEventListener("vtt:encounters-changed", changed);
    return () => window.removeEventListener("vtt:encounters-changed", changed);
  }, [refresh]);
  useEffect(() => {
    if (!campaignId) return;
    const controller = new AbortController();
    const timer = window.setTimeout(
      () =>
        void api<CatalogResults>(
          `/catalog/monsters?campaignId=${campaignId}&edition=${ruleset}&query=${encodeURIComponent(query)}&perPage=20`,
          { signal: controller.signal },
        )
          .then((r) => setResults(r.data))
          .catch((e) => {
            if (!controller.signal.aborted) setMessage(e.message);
          }),
      180,
    );
    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [campaignId, query, ruleset]);

  async function save() {
    if (!campaignId || !choices.length) return;
    const levels = party
      .split(",")
      .map((value) => Number(value.trim()))
      .filter((value) => Number.isInteger(value) && value >= 1 && value <= 20)
      .map((level) => ({ level }));
    try {
      await api(`/campaigns/${campaignId}/encounter-drafts`, {
        method: "POST",
        body: JSON.stringify({
          name,
          party: levels,
          creatures: choices.map(({ catalogEntryId, quantity }) => ({ catalogEntryId, quantity })),
        }),
      });
      setChoices([]);
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível salvar o encontro.");
    }
  }

  async function instantiate(draft: Draft) {
    if (!targetSceneId || busyDraft !== null) return;
    const requestId = instantiateRequests.current.get(draft.id) ?? crypto.randomUUID();
    instantiateRequests.current.set(draft.id, requestId);
    setBusyDraft(draft.id);
    setMessage("");
    try {
      const result = await api<InstantiateResult>(`/encounter-drafts/${draft.id}/instantiate`, {
        method: "POST",
        body: JSON.stringify({
          sceneId: targetSceneId,
          requestId,
          x: Math.max(0, startX),
          y: Math.max(0, startY),
          hidden,
          addToCombat: combatMode === "add",
          startCombat: combatMode === "start",
        }),
      });
      instantiateRequests.current.delete(draft.id);
      result.actors.forEach(upsertActor);
      if (targetSceneId === sceneId) {
        patchState(result.state);
        if (result.combat) setCombat(result.combat);
      }
      setMessage(
        `${draft.name}: ${result.actors.length} criatura(s) colocada(s) em ${scenes.find((scene) => scene.id === targetSceneId)?.name ?? "cena"}${result.existing ? " (repetição segura)" : ""}.`,
      );
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Não foi possível colocar o encontro na cena.",
      );
    } finally {
      setBusyDraft(null);
    }
  }

  return (
    <section className="encounter-builder">
      <h3>Construtor de encontros</h3>
      <label>
        Nome
        <input value={name} onChange={(e) => setName(e.target.value)} />
      </label>
      <label>
        Níveis do grupo, separados por vírgula
        <input value={party} onChange={(e) => setParty(e.target.value)} placeholder="5,5,5,5" />
      </label>
      <label>
        Buscar criaturas
        <input value={query} onChange={(e) => setQuery(e.target.value)} />
      </label>
      <div>
        {results.map((entry) => (
          <button
            type="button"
            key={entry.id}
            onClick={() =>
              setChoices((current) =>
                current.some((c) => c.catalogEntryId === entry.id)
                  ? current.map((c) =>
                      c.catalogEntryId === entry.id ? { ...c, quantity: c.quantity + 1 } : c,
                    )
                  : [...current, { catalogEntryId: entry.id, quantity: 1, name: entry.name }],
              )
            }
          >
            + {entry.name} · {entry.source}
          </button>
        ))}
      </div>
      {choices.map((choice) => (
        <p key={choice.catalogEntryId}>
          {choice.name} ×{" "}
          <input
            aria-label={`Quantidade de ${choice.name}`}
            type="number"
            min={1}
            max={50}
            value={choice.quantity}
            onChange={(e) =>
              setChoices((current) =>
                current.map((c) =>
                  c.catalogEntryId === choice.catalogEntryId
                    ? { ...c, quantity: Number(e.target.value) }
                    : c,
                ),
              )
            }
          />{" "}
          <button
            type="button"
            onClick={() =>
              setChoices((current) =>
                current.filter((c) => c.catalogEntryId !== choice.catalogEntryId),
              )
            }
          >
            Remover
          </button>
        </p>
      ))}
      <button disabled={!choices.length} onClick={() => void save()}>
        Salvar rascunho
      </button>
      {!!drafts.length && (
        <fieldset>
          <legend>Colocar encontro em cena</legend>
          <label>
            Cena
            <select
              value={targetSceneId ?? ""}
              onChange={(event) => setTargetSceneId(Number(event.target.value) || null)}
            >
              <option value="">Escolha uma cena</option>
              {scenes.map((scene) => (
                <option value={scene.id} key={scene.id}>
                  {scene.name}
                  {scene.published ? " · publicada" : ""}
                </option>
              ))}
            </select>
          </label>
          <div className="editor-grid">
            <label>
              X inicial
              <input
                type="number"
                min={0}
                value={startX}
                onChange={(event) => setStartX(Number(event.target.value))}
              />
            </label>
            <label>
              Y inicial
              <input
                type="number"
                min={0}
                value={startY}
                onChange={(event) => setStartY(Number(event.target.value))}
              />
            </label>
          </div>
          <label className="check-label">
            <input
              type="checkbox"
              checked={hidden}
              onChange={(event) => setHidden(event.target.checked)}
            />
            Criar tokens ocultos dos jogadores
          </label>
          <label>
            Combate
            <select
              value={combatMode}
              onChange={(event) => setCombatMode(event.target.value as typeof combatMode)}
            >
              <option value="none">Só colocar na cena</option>
              <option value="add">Adicionar ao combate ativo</option>
              <option value="start">Iniciar novo combate com estas criaturas</option>
            </select>
          </label>
        </fieldset>
      )}
      {drafts.map((draft) => (
        <article key={draft.id}>
          <b>{draft.name}</b>
          <p>{draft.creatures.map((c) => `${c.quantity}× ${c.name}`).join(", ")}</p>
          <small>
            {draft.difficulty.total} XP
            {draft.difficulty.ruleset === "5e-2024"
              ? " de orçamento"
              : ` base · ${draft.difficulty.adjusted} XP ajustado`}{" "}
            · {draft.difficulty.rating}
          </small>
          <div>
            <button
              type="button"
              disabled={!targetSceneId || busyDraft !== null}
              onClick={() => void instantiate(draft)}
            >
              {busyDraft === draft.id
                ? "Colocando…"
                : combatMode === "start"
                  ? "Colocar e iniciar combate"
                  : combatMode === "add"
                    ? "Colocar e adicionar ao combate"
                    : "Colocar na cena"}
            </button>
          </div>
        </article>
      ))}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
