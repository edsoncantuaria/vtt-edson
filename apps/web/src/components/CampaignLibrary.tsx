import { useEffect, useState } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";

export function CampaignLibrary() {
  const { campaignId, ruleset } = useSession();
  const [sources, setSources] = useState<string[]>([]);
  const [selected, setSelected] = useState<string[] | null>([]);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    api<{ sources: string[]; selected: string[] | null }>(
      `/campaigns/${campaignId}/catalog-sources`,
      { signal: controller.signal },
    )
      .then((result) => {
        setSources(result.sources);
        setSelected(result.selected);
      })
      .catch((error) => {
        if (!controller.signal.aborted) setMessage(error.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [campaignId]);
  async function save() {
    setBusy(true);
    setMessage("");
    try {
      await api(`/campaigns/${campaignId}/catalog-sources`, {
        method: "PUT",
        body: JSON.stringify({ sources: selected }),
      });
      setMessage(
        "Biblioteca salva. As próximas consultas e o assistente de fichas usarão estas fontes.",
      );
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível salvar.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <details className="campaign-library">
      <summary>Biblioteca da campanha</summary>
      <p>
        Escolha as fontes disponíveis no compêndio e na criação de personagens. Fichas existentes
        são preservadas.
      </p>
      {loading ? (
        <p>Carregando fontes…</p>
      ) : (
        <>
          <label className="check-label">
            <input
              type="checkbox"
              checked={selected === null}
              onChange={(event) =>
                setSelected(
                  event.target.checked
                    ? null
                    : sources.filter((source) =>
                        (ruleset === "5e-2024"
                          ? ["XPHB", "XDMG", "XMM"]
                          : ["PHB", "DMG", "MM"]
                        ).includes(source),
                      ),
                )
              }
            />{" "}
            Usar todo o acervo
          </label>
          {selected !== null && (
            <>
              <label>
                Buscar fonte
                <input
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="PHB, XPHB, TCE…"
                />
              </label>
              <div style={{ maxHeight: 240, overflowY: "auto" }}>
                {sources
                  .filter((source) => source.toLowerCase().includes(query.toLowerCase()))
                  .map((source) => (
                    <label className="check-label" key={source}>
                      <input
                        type="checkbox"
                        checked={selected.includes(source)}
                        onChange={(event) =>
                          setSelected((current) =>
                            event.target.checked
                              ? [...(current ?? []), source]
                              : (current ?? []).filter((item) => item !== source),
                          )
                        }
                      />
                      {source}
                    </label>
                  ))}
              </div>
              <p>
                {selected.length} fontes selecionadas. O Livro do Jogador continua acessível para
                leitura.
              </p>
            </>
          )}
          <button disabled={busy} onClick={() => void save()}>
            {busy ? "Salvando…" : "Salvar biblioteca"}
          </button>
        </>
      )}
      {message && <p role="status">{message}</p>}
    </details>
  );
}
