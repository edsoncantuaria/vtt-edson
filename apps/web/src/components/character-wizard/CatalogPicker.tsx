import { useEffect, useState } from "react";
import { api } from "../../lib/api";
import type { CatalogEntry, CatalogKind, CatalogResults } from "../../lib/catalog";
import { useSession } from "../../store/session";

export function CatalogPicker({
  kind,
  edition,
  value,
  onChange,
  classId,
  maxLevel,
}: {
  kind: CatalogKind;
  edition: string;
  value: CatalogEntry | null;
  onChange: (v: CatalogEntry) => void;
  classId?: number;
  maxLevel?: number;
}) {
  const campaignId = useSession((state) => state.campaignId);
  const [source, setSource] = useState("");
  const [school, setSchool] = useState("");
  const [ritual, setRitual] = useState(false);
  const [outsideList, setOutsideList] = useState(false);
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<CatalogResults | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setError("");
    setResult(null);
    const timer = setTimeout(() => {
      void api<CatalogResults>(
        `/catalog/${kind}?campaignId=${campaignId}&edition=${edition}&source=${encodeURIComponent(source)}&query=${encodeURIComponent(query)}&perPage=20&page=${page}${classId && !outsideList ? "&classId=" + classId : ""}${maxLevel !== undefined && !outsideList ? "&maxLevel=" + maxLevel : ""}${school ? "&school=" + school : ""}${ritual ? "&ritual=1" : ""}`,
        { signal: controller.signal },
      )
        .then(setResult)
        .catch((e) => {
          if (!controller.signal.aborted) setError(e.message);
        });
    }, 200);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [
    kind,
    edition,
    query,
    page,
    retry,
    campaignId,
    source,
    classId,
    maxLevel,
    school,
    ritual,
    outsideList,
  ]);
  return (
    <div className="wizard-picker">
      {kind === "spells" && (
        <>
          <label>
            Escola
            <select
              value={school}
              onChange={(e) => {
                setSchool(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Todas</option>
              {Object.entries({
                A: "Abjuração",
                C: "Conjuração",
                D: "Adivinhação",
                E: "Encantamento",
                V: "Evocação",
                I: "Ilusão",
                N: "Necromancia",
                T: "Transmutação",
              }).map(([key, label]) => (
                <option value={key} key={key}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="check-label">
            <input
              type="checkbox"
              checked={ritual}
              onChange={(e) => {
                setRitual(e.target.checked);
                setPage(1);
              }}
            />
            Somente rituais
          </label>
          {classId && (
            <label className="check-label">
              <input
                type="checkbox"
                checked={outsideList}
                onChange={(e) => {
                  setOutsideList(e.target.checked);
                  setPage(1);
                }}
              />
              Consultar fora da lista e do nível · requer revisão da mesa
            </label>
          )}
        </>
      )}
      <label>
        Fonte
        <select
          value={source}
          onChange={(event) => {
            setSource(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Todas as fontes da campanha</option>
          {result?.sources.map((item) => (
            <option key={item}>{item}</option>
          ))}
        </select>
      </label>
      <input
        aria-label="Buscar opções"
        placeholder="Buscar pelo nome original…"
        value={query}
        onChange={(e) => {
          setQuery(e.target.value);
          setPage(1);
        }}
      />
      {value && (
        <p className="wizard-selection">
          Selecionado: <b>{value.name}</b> · {value.source}
        </p>
      )}
      {error ? (
        <p role="alert">
          {error} <button onClick={() => setRetry((n) => n + 1)}>Tentar novamente</button>
        </p>
      ) : !result ? (
        <p role="status">Carregando opções…</p>
      ) : (
        <>
          <div className="wizard-options">
            {result.data.map((entry) => (
              <button
                key={entry.id}
                aria-pressed={value?.id === entry.id}
                onClick={() => onChange(entry)}
              >
                <b>{entry.name}</b>
                <small>{entry.source}</small>
              </button>
            ))}
          </div>
          {!result.total && (
            <p>
              Nenhuma opção encontrada com estes filtros. Listas de classe exigem o catálogo de
              magias enriquecido.
            </p>
          )}
          {result.last_page > 1 && (
            <nav>
              <button disabled={page === 1} onClick={() => setPage((n) => n - 1)}>
                Anterior
              </button>
              <span>
                {page}/{result.last_page}
              </span>
              <button disabled={page === result.last_page} onClick={() => setPage((n) => n + 1)}>
                Próxima
              </button>
            </nav>
          )}
        </>
      )}
      {value && (
        <details>
          <summary>Ler {value.name}</summary>
          <p className="catalog-prose">
            {String(
              value.data.description || "Características de classe serão incluídas na revisão.",
            )}
          </p>
        </details>
      )}
    </div>
  );
}
