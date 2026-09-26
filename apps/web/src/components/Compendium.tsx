import type { Actor } from "@vtt/core";
import { useEffect, useRef, useState } from "react";
import { api } from "../lib/api";
import {
  CATALOG_KINDS,
  type CatalogEntry,
  type CatalogKind,
  type CatalogResults,
  type HomebrewCatalogEntry,
} from "../lib/catalog";
import { monsterDataToActorSystem } from "../lib/monsterMapping";
import { updateScene } from "../lib/scene";
import { isManagerRole, useSession } from "../store/session";
import { CatalogEntryCard } from "./compendium/CatalogEntryCard";
import { CatalogFilters } from "./compendium/CatalogFilters";
import { HomebrewSection } from "./compendium/HomebrewSection";
import { Icon } from "./Icon";
import "./Compendium.css";

const CAMPAIGN_SUBSYSTEM_KINDS: CatalogKind[] = [
  "bastions",
  "vehicles",
  "decks",
  "recipes",
  "psionics",
  "rewards",
  "deities",
  "languages",
  "hazards",
  "objects",
  "cults",
];
const MATERIALIZED_TOOL_KINDS: CatalogKind[] = ["encounters", "loot"];
const REFERENCE_ONLY_KINDS: CatalogKind[] = [
  "books",
  "adventures",
  "cards",
  "legendary-groups",
  "rules",
];

export function Compendium() {
  const { role, user, ruleset, actors, selectedActorId, campaignId, sceneId, upsertActor } =
    useSession();
  const [optional, setOptional] = useState(false);
  const [kind, setKind] = useState<CatalogKind>("all");
  const [scope, setScope] = useState<"library" | "campaign" | "homebrew">("library");
  const [edition, setEdition] = useState<string>(ruleset);
  const [source, setSource] = useState("");
  const [level, setLevel] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [results, setResults] = useState<CatalogResults | null>(null);
  const [sourceNames, setSourceNames] = useState<Record<string, string>>({});
  const [expanded, setExpanded] = useState<number | null>(null);
  const [expandedHomebrew, setExpandedHomebrew] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [target, setTarget] = useState(selectedActorId ? String(selectedActorId) : "");
  const [retry, setRetry] = useState(0);
  const pending = useRef(false);

  const editableActors = actors.filter(
    (actor) => isManagerRole(role) || actor.ownerUserId === user?.id,
  );
  const targetIsValid = editableActors.some((actor) => actor.id === Number(target));
  function canAdd(entry: CatalogEntry): boolean {
    if (entry.edition !== ruleset) return false;
    const campaignIntegrated =
      MATERIALIZED_TOOL_KINDS.includes(entry.kind) || CAMPAIGN_SUBSYSTEM_KINDS.includes(entry.kind);
    return entry.kind === "monsters" || campaignIntegrated ? isManagerRole(role) : targetIsValid;
  }

  useEffect(() => {
    if (!campaignId) return;
    const controller = new AbortController();
    void api<{ sourceNames: Record<string, string> }>(`/campaigns/${campaignId}/catalog-sources`, {
      signal: controller.signal,
    })
      .then((result) => setSourceNames(result.sourceNames ?? {}))
      .catch(() => {});
    return () => controller.abort();
  }, [campaignId]);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    setResults(null);
    setExpanded(null);
    setExpandedHomebrew(null);
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        query,
        scope,
        campaignId: String(campaignId ?? ""),
        edition,
        source,
        perPage: "20",
        page: String(page),
      });
      if (level) params.set("level", level);
      void api<CatalogResults>(`/catalog/${kind}?${params.toString()}`, {
        signal: controller.signal,
      })
        .then(setResults)
        .catch((searchError) => {
          if (!controller.signal.aborted) setError(searchError.message);
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 250);
    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [kind, query, page, retry, edition, source, level, campaignId, scope]);

  async function share(entry: CatalogEntry) {
    if (!campaignId || busy) return;
    setBusy(true);
    try {
      const result = await api<{ shared: boolean }>(
        `/campaigns/${campaignId}/catalog/${entry.id}/share`,
        {
          method: "PUT",
          body: JSON.stringify({ shared: !entry.shared }),
        },
      );
      setResults((current) =>
        current
          ? {
              ...current,
              data: current.data.map((item) =>
                item.id === entry.id ? { ...item, shared: result.shared } : item,
              ),
            }
          : current,
      );
      setNotice(
        result.shared ? "Capítulo compartilhado com esta mesa." : "Compartilhamento removido.",
      );
    } catch (shareError) {
      setError(shareError instanceof Error ? shareError.message : "Não foi possível compartilhar.");
    } finally {
      setBusy(false);
    }
  }

  async function add(entry: CatalogEntry) {
    if (pending.current) return;
    pending.current = true;
    setBusy(true);
    setNotice("");
    setError("");
    try {
      if (entry.edition !== ruleset)
        throw new Error("Este conteúdo pertence a outra edição das regras da campanha.");
      if (entry.kind === "monsters") {
        await addMonster(entry);
      } else if (MATERIALIZED_TOOL_KINDS.includes(entry.kind)) {
        await integrateTool(entry);
      } else if (CAMPAIGN_SUBSYSTEM_KINDS.includes(entry.kind)) {
        await integrateSubsystem(entry);
      } else {
        await addToActor(entry);
      }
    } catch (addError) {
      setError(addError instanceof Error ? addError.message : "Não foi possível adicionar.");
    } finally {
      setBusy(false);
      pending.current = false;
    }
  }

  async function addMonster(entry: CatalogEntry) {
    if (!campaignId || !sceneId || !isManagerRole(role)) return;
    const result = await api<{ actor: Actor }>(`/campaigns/${campaignId}/actors`, {
      method: "POST",
      body: JSON.stringify({
        type: "monster",
        name: entry.name,
        system: {
          ...monsterDataToActorSystem(entry.data),
          origin: entry.homebrewId
            ? {
                kind: "homebrew",
                slug: entry.slug,
                source: entry.source,
                edition: entry.edition,
                version: entry.homebrewVersion?.toString(),
              }
            : {
                kind: "catalog",
                slug: entry.slug,
                source: entry.source,
                edition: entry.edition,
                contentHash: entry.content_hash ?? null,
              },
          ...(entry.id > 0 && entry.data.tokenUrl
            ? { tokenImageUrl: `/api/catalog-media/${entry.id}/token` }
            : {}),
        },
      }),
    });
    upsertActor(result.actor);
    try {
      await updateScene(sceneId, "/tokens", {
        x: 350,
        y: 350,
        name: entry.name,
        actorId: result.actor.id,
      });
      setNotice(`${entry.name} adicionado à cena.`);
    } catch {
      setNotice("Ficha criada. Não foi possível colocar o token; adicione-o no painel Cena.");
    }
  }

  async function integrateTool(entry: CatalogEntry) {
    if (!campaignId || !isManagerRole(role)) return;
    const result = await api<{ tables: Array<{ id: number; name: string }> }>(
      `/campaigns/${campaignId}/catalog/${entry.id}/integrate`,
      { method: "POST" },
    );
    setNotice(
      `${entry.name} integrado à campanha em ${result.tables.length} tabela${result.tables.length === 1 ? "" : "s"}.`,
    );
  }

  async function integrateSubsystem(entry: CatalogEntry) {
    if (!campaignId || !isManagerRole(role)) return;
    await api(`/campaigns/${campaignId}/subsystems`, {
      method: "POST",
      body: JSON.stringify({ catalogEntryId: entry.id }),
    });
    setNotice(`${entry.name} ativado como subsistema jogável da campanha.`);
  }

  async function addToActor(entry: CatalogEntry) {
    const selected = editableActors.find((actor) => actor.id === Number(target));
    if (!selected) return;
    const description =
      typeof entry.data.desc === "string"
        ? entry.data.desc
        : typeof entry.data.description === "string"
          ? entry.data.description
          : undefined;
    const documentKind =
      entry.kind === "spells"
        ? "spell"
        : ["items", "magic-variants"].includes(entry.kind)
          ? "item"
          : "feature";
    const rawDamage =
      typeof entry.data.damage === "string"
        ? entry.data.damage.match(/\b\d*d\d+(?:[+-]\d+)?\b/i)?.[0]
        : undefined;
    const customData =
      documentKind === "spell"
        ? {
            id: crypto.randomUUID(),
            slug: entry.slug,
            name: entry.name,
            level: entry.level ?? 0,
            prepared: false,
            description,
          }
        : documentKind === "item"
          ? {
              id: crypto.randomUUID(),
              slug: entry.slug,
              name: entry.name,
              quantity: 1,
              equipped: false,
              description:
                description ??
                (typeof entry.data.effect === "string" ? entry.data.effect : undefined),
              damage: rawDamage,
            }
          : {
              id: crypto.randomUUID(),
              slug: entry.slug,
              name: entry.name,
              source: entry.source,
              description,
            };
    const result = await api<{ actor: Actor }>(`/actors/${selected.id}/documents`, {
      method: "POST",
      body: JSON.stringify(
        entry.id > 0
          ? { catalogEntryId: entry.id, kind: documentKind }
          : {
              kind: documentKind,
              name: entry.name,
              source: entry.homebrewId ? "Homebrew" : entry.source,
              data: {
                ...customData,
                origin: {
                  kind: "homebrew",
                  slug: entry.slug,
                  source: entry.source,
                  edition: entry.edition,
                  homebrewEntryId: entry.homebrewId,
                  packageId: entry.packageId,
                  version: entry.homebrewVersion,
                },
              },
            },
      ),
    });
    upsertActor(result.actor);
    setNotice(`${entry.name} adicionado a ${selected.name} com vínculo de origem.`);
  }

  function homebrewEntry(entry: HomebrewCatalogEntry): CatalogEntry {
    const rawLevel = entry.data.level ?? entry.data.raw?.level;
    const levelValue = typeof rawLevel === "number" ? rawLevel : Number(rawLevel);
    return {
      id: -entry.homebrewId,
      homebrewId: entry.homebrewId,
      packageId: entry.packageId,
      homebrewVersion: entry.version,
      slug: entry.slug,
      kind: entry.kind,
      name: entry.name,
      source: `Homebrew · ${entry.package?.name ?? "Mesa"}`,
      edition: ruleset,
      ...(Number.isFinite(levelValue) ? { level: levelValue } : {}),
      data: entry.data,
    };
  }

  function selectKind(nextKind: CatalogKind) {
    setKind(nextKind);
    setPage(1);
    setSource("");
    setLevel("");
  }

  return (
    <div className="compendium">
      <div className="catalog-filters" role="group" aria-label="Origem dos conteúdos">
        {(
          [
            ["library", "Biblioteca"],
            ["campaign", "Na campanha"],
            ["homebrew", "Homebrew"],
          ] as const
        ).map(([value, label]) => (
          <button
            key={value}
            className={scope === value ? "primary" : ""}
            aria-pressed={scope === value}
            onClick={() => {
              setScope(value);
              setPage(1);
              setExpanded(null);
              setExpandedHomebrew(null);
              setSource("");
            }}
          >
            {label}
          </button>
        ))}
      </div>
      <CatalogFilters
        optional={optional}
        kind={kind}
        edition={edition}
        source={source}
        level={level}
        query={query}
        results={results}
        sourceNames={sourceNames}
        target={target}
        editableActors={editableActors}
        showTarget={
          kind === "all" ||
          ![
            "monsters",
            ...REFERENCE_ONLY_KINDS,
            ...MATERIALIZED_TOOL_KINDS,
            ...CAMPAIGN_SUBSYSTEM_KINDS,
          ].includes(kind)
        }
        onOptional={(value) => {
          setOptional(value);
          if (!value && CATALOG_KINDS.find((item) => item.id === kind)?.optional)
            selectKind("spells");
        }}
        onKind={selectKind}
        onEdition={(value) => {
          setEdition(value);
          setPage(1);
          setSource("");
        }}
        onSource={(value) => {
          setSource(value);
          setPage(1);
        }}
        onLevel={(value) => {
          setLevel(value);
          setPage(1);
        }}
        onQuery={(value) => {
          setQuery(value);
          setPage(1);
        }}
        onTarget={setTarget}
      />

      {notice && (
        <p className="compendium-notice" role="status">
          <Icon name="check" size={15} />
          {notice}
        </p>
      )}
      {error && (
        <div className="notice notice--error" role="alert">
          {error}
          <button onClick={() => setRetry((value) => value + 1)}>Tentar novamente</button>
        </div>
      )}
      <div className="compendium-results">
        <span>
          {loading
            ? "Buscando no acervo…"
            : `${(results?.total ?? 0) + (results?.homebrew?.length ?? 0)} resultados`}
        </span>
        <small>Biblioteca · conteúdo da campanha · homebrew</small>
      </div>
      {!loading && !error && !results?.data.length && !results?.homebrew?.length && (
        <div className="panel-empty">
          <Icon name="search" size={32} />
          <h3>
            {scope === "campaign"
              ? "Nenhum conteúdo adicionado à campanha ainda."
              : scope === "homebrew"
                ? "Nenhum homebrew habilitado nesta categoria."
                : "Nenhum resultado encontrado."}
          </h3>
          <p>
            {scope === "campaign"
              ? "Use a Biblioteca para adicionar conteúdo às fichas, cenas ou ferramentas da campanha."
              : "Experimente outro nome ou altere os filtros de fonte e edição."}
          </p>
        </div>
      )}

      {results?.data.map((entry) => (
        <CatalogEntryCard
          key={entry.id}
          entry={entry}
          kind={entry.kind}
          expanded={expanded === entry.id}
          role={role}
          ruleset={ruleset}
          campaignId={campaignId}
          busy={busy}
          canAdd={canAdd(entry)}
          onToggle={() => setExpanded(expanded === entry.id ? null : entry.id)}
          onShare={() => void share(entry)}
          onAdd={() => void add(entry)}
        />
      ))}

      <HomebrewSection
        entries={results?.homebrew ?? []}
        expandedId={expandedHomebrew}
        busy={busy}
        canAdd={canAdd}
        toCatalogEntry={homebrewEntry}
        onToggle={(id) => setExpandedHomebrew(expandedHomebrew === id ? null : id)}
        onAdd={(entry) => void add(entry)}
      />

      {results && results.last_page > 1 && (
        <nav className="compendium-pagination" aria-label="Páginas do compêndio">
          <button
            disabled={page === 1 || loading}
            onClick={() => setPage((value) => value - 1)}
            aria-label="Página anterior"
          >
            <Icon name="back" size={16} />
          </button>
          <span>
            {page} de {results.last_page}
          </span>
          <button
            disabled={page === results.last_page || loading}
            onClick={() => setPage((value) => value + 1)}
            aria-label="Próxima página"
          >
            <Icon name="arrow" size={16} />
          </button>
        </nav>
      )}
    </div>
  );
}
