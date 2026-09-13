import { useCallback, useEffect, useState } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";

type Entry = { min: number; max: number; label: string; result?: Record<string, unknown> };
type Table = { id: number; name: string; formula: string; enabled: boolean; entries: Entry[] };
type RollResult = { record: { id: number }; roll: { total: number; detail: string }; entry: Entry };

export function RollTableManager({
  onLootResult,
}: {
  onLootResult?: (result: {
    rollTableRollId: number;
    name: string;
    data: Record<string, unknown>;
  }) => void;
}) {
  const { campaignId, role } = useSession();
  const [tables, setTables] = useState<Table[]>([]);
  const [message, setMessage] = useState("");
  const refresh = useCallback(async () => {
    if (campaignId)
      setTables((await api<{ tables: Table[] }>(`/campaigns/${campaignId}/roll-tables`)).tables);
  }, [campaignId]);
  useEffect(() => {
    void refresh().catch((e) => setMessage(e.message));
  }, [refresh]);

  async function create(form: HTMLFormElement) {
    if (!campaignId) return;
    const fields = new FormData(form);
    try {
      const entries = JSON.parse(String(fields.get("entries"))) as Entry[];
      await api(`/campaigns/${campaignId}/roll-tables`, {
        method: "POST",
        body: JSON.stringify({ name: fields.get("name"), formula: fields.get("formula"), entries }),
      });
      form.reset();
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Revise a tabela.");
    }
  }

  async function roll(table: Table) {
    try {
      const result = await api<RollResult>(`/roll-tables/${table.id}/roll`, { method: "POST" });
      setMessage(`${table.name}: ${result.roll.detail} → ${result.entry.label}`);
      if (result.entry.result)
        onLootResult?.({
          rollTableRollId: result.record.id,
          name: result.entry.label,
          data: result.entry.result,
        });
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível rolar a tabela.");
    }
  }

  return (
    <section className="roll-table-manager">
      <h3>Tabelas roláveis</h3>
      {role === "gm" && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void create(e.currentTarget);
          }}
        >
          <label>
            Nome
            <input name="name" required />
          </label>
          <label>
            Fórmula
            <input name="formula" defaultValue="1d100" required />
          </label>
          <label>
            Faixas JSON
            <textarea
              name="entries"
              defaultValue={'[{"min":1,"max":100,"label":"Resultado"}]'}
              required
            />
          </label>
          <button>Criar tabela</button>
        </form>
      )}
      {tables.map((table) => (
        <article key={table.id}>
          <b>{table.name}</b>
          <small>
            {table.formula} · {table.enabled ? "ativa" : "desativada"}
          </small>
          <button disabled={!table.enabled} onClick={() => void roll(table)}>
            Rolar
          </button>
        </article>
      ))}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
