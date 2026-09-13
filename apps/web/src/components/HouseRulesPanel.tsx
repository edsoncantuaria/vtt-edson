import { useEffect, useState } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";
type Rule = {
  id: string;
  name: string;
  match: string;
  modifier: number;
  formula?: string;
  enabled: boolean;
};
export function HouseRulesPanel() {
  const { campaignId } = useSession();
  const [rules, setRules] = useState<Rule[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    const c = new AbortController();
    void api<{ rules: Rule[] }>(`/campaigns/${campaignId}/house-rules`, { signal: c.signal })
      .then((r) => setRules(r.rules))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [campaignId]);
  function edit(index: number, patch: Partial<Rule>) {
    setSaved(false);
    setRules((list) => list.map((r, i) => (i === index ? { ...r, ...patch } : r)));
  }
  return (
    <section>
      <h3>Regras da casa</h3>
      <p>
        Ajustes exclusivos desta campanha. O nome do teste ou ação deve conter o texto indicado.
        Regras ativas somam seus modificadores; a última substituição de fórmula prevalece.
      </p>
      {loading ? (
        <p>Carregando…</p>
      ) : (
        <form
          onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError("");
            try {
              await api(`/campaigns/${campaignId}/house-rules`, {
                method: "PUT",
                body: JSON.stringify({ rules }),
              });
              setSaved(true);
            } catch (e) {
              setError(e instanceof Error ? e.message : "Não foi possível salvar.");
            } finally {
              setBusy(false);
            }
          }}
        >
          <fieldset disabled={busy}>
            {rules.map((rule, i) => (
              <div className="editor-item" key={rule.id}>
                <label>
                  Nome da regra
                  <input
                    required
                    maxLength={100}
                    value={rule.name}
                    onChange={(e) => edit(i, { name: e.target.value })}
                  />
                </label>
                <label>
                  Aplicar quando o nome contém
                  <input
                    required
                    minLength={2}
                    maxLength={100}
                    placeholder="Percepção"
                    value={rule.match}
                    onChange={(e) => edit(i, { match: e.target.value })}
                  />
                </label>
                <label>
                  Modificador
                  <input
                    type="number"
                    required
                    min={-100}
                    max={100}
                    value={rule.modifier}
                    onChange={(e) => edit(i, { modifier: Number(e.target.value) })}
                  />
                </label>
                <label>
                  Substituir fórmula (opcional)
                  <input
                    placeholder="1d20+3"
                    value={rule.formula ?? ""}
                    onChange={(e) => edit(i, { formula: e.target.value })}
                  />
                </label>
                <label className="check-label">
                  <input
                    type="checkbox"
                    checked={rule.enabled}
                    onChange={(e) => edit(i, { enabled: e.target.checked })}
                  />
                  Ativa
                </label>
                <button
                  type="button"
                  onClick={() => {
                    setRules(rules.filter((_, n) => n !== i));
                    setSaved(false);
                  }}
                >
                  Remover regra
                </button>
              </div>
            ))}
            <button
              type="button"
              disabled={rules.length >= 12}
              onClick={() => {
                setRules([
                  ...rules,
                  {
                    id: crypto.randomUUID(),
                    name: "Nova regra",
                    match: "Percepção",
                    modifier: -1,
                    enabled: true,
                  },
                ]);
                setSaved(false);
              }}
            >
              Adicionar regra
            </button>
            <button className="primary">{busy ? "Salvando…" : "Salvar regras da mesa"}</button>
          </fieldset>
        </form>
      )}
      {error && <p role="alert">{error}</p>}
      {saved && <p role="status">Regras salvas para esta campanha.</p>}
    </section>
  );
}
