import { useCallback, useEffect, useState } from "react";
import { api } from "../lib/api";
import { isManagerRole, useSession } from "../store/session";

type Entry = {
  id: number;
  kind: string;
  name: string;
  slug: string;
  version: string;
  data: Record<string, unknown>;
};
type Package = {
  id: number;
  name: string;
  version: string;
  enabled: boolean;
  description?: string | null;
  entries: Entry[];
};

export function HomebrewManager() {
  const { campaignId, role } = useSession();
  const [packages, setPackages] = useState<Package[]>([]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(
    async (signal?: AbortSignal) => {
      if (!campaignId) return;
      const result = await api<{ packages: Package[] }>(`/campaigns/${campaignId}/homebrew`, {
        signal,
      });
      setPackages(result.packages);
    },
    [campaignId],
  );

  useEffect(() => {
    const controller = new AbortController();
    void refresh(controller.signal).catch((error) => {
      if (!controller.signal.aborted) setMessage(error.message);
    });
    return () => controller.abort();
  }, [refresh]);

  async function createPackage(form: HTMLFormElement) {
    if (!campaignId) return;
    const fields = new FormData(form);
    setBusy(true);
    setMessage("");
    try {
      await api(`/campaigns/${campaignId}/homebrew`, {
        method: "POST",
        body: JSON.stringify({
          name: fields.get("name"),
          version: fields.get("version"),
          description: fields.get("description") || null,
        }),
      });
      form.reset();
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Não foi possível criar o pacote.");
    } finally {
      setBusy(false);
    }
  }

  async function addEntry(homebrewPackage: Package, form: HTMLFormElement) {
    const fields = new FormData(form);
    setBusy(true);
    setMessage("");
    try {
      const raw = String(fields.get("data") ?? "{}");
      const data = JSON.parse(raw) as Record<string, unknown>;
      await api(`/homebrew/${homebrewPackage.id}/entries`, {
        method: "POST",
        body: JSON.stringify({
          kind: fields.get("kind"),
          name: fields.get("name"),
          version: fields.get("version"),
          data,
        }),
      });
      form.reset();
      await refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Revise os dados da entrada.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="homebrew-manager">
      <h3>Homebrew da campanha</h3>
      <p>
        Pacotes versionados agrupam regras e conteúdo estruturado. Pacotes desativados ficam
        preservados sem aparecer para jogadores.
      </p>
      {isManagerRole(role) && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            void createPackage(event.currentTarget);
          }}
        >
          <label>
            Nome
            <input name="name" required maxLength={160} />
          </label>
          <label>
            Versão
            <input name="version" required defaultValue="1.0.0" maxLength={40} />
          </label>
          <label>
            Descrição
            <textarea name="description" maxLength={5000} />
          </label>
          <button disabled={busy}>Criar pacote</button>
        </form>
      )}
      {packages.map((pkg) => (
        <details key={pkg.id} open>
          <summary>
            {pkg.name} · v{pkg.version} · {pkg.enabled ? "ativo" : "desativado"}
          </summary>
          {isManagerRole(role) && (
            <button
              disabled={busy}
              onClick={() => {
                setBusy(true);
                void api(`/homebrew/${pkg.id}`, {
                  method: "PATCH",
                  body: JSON.stringify({ enabled: !pkg.enabled }),
                })
                  .then(() => refresh())
                  .catch((e) => setMessage(e.message))
                  .finally(() => setBusy(false));
              }}
            >
              {pkg.enabled ? "Desativar" : "Ativar"}
            </button>
          )}
          {pkg.entries.map((entry) => (
            <article key={entry.id}>
              <b>{entry.name}</b>
              <small>
                {entry.kind} · v{entry.version}
              </small>
              <pre>{JSON.stringify(entry.data, null, 2)}</pre>
            </article>
          ))}
          {isManagerRole(role) && (
            <form
              onSubmit={(event) => {
                event.preventDefault();
                void addEntry(pkg, event.currentTarget);
              }}
            >
              <label>
                Tipo
                <input name="kind" required placeholder="feat, spell, rule…" maxLength={40} />
              </label>
              <label>
                Nome
                <input name="name" required maxLength={160} />
              </label>
              <label>
                Versão
                <input name="version" required defaultValue={pkg.version} maxLength={40} />
              </label>
              <label>
                Dados JSON
                <textarea name="data" required defaultValue="{}" />
              </label>
              <button disabled={busy}>Adicionar entrada</button>
            </form>
          )}
        </details>
      ))}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
