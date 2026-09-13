import { useCallback, useEffect, useState } from "react";
import { api } from "../../lib/api";
import { isManagerRole, useSession } from "../../store/session";

export type CampaignMacro = {
  id: number;
  name: string;
  icon?: string | null;
  commands: Array<Record<string, unknown>>;
  visibility: "owner" | "campaign" | "gm";
  hotbar_slot?: number | null;
  enabled: boolean;
};

type CampaignModule = {
  id: number;
  module_id: string;
  name: string;
  version: string;
  permissions: string[];
  enabled: boolean;
};

export function MacroManager() {
  const { campaignId, role, setError } = useSession();
  const [macros, setMacros] = useState<CampaignMacro[]>([]);
  const [modules, setModules] = useState<CampaignModule[]>([]);
  const [name, setName] = useState("");
  const [formula, setFormula] = useState("1d20");
  const [slot, setSlot] = useState(1);
  const [visibility, setVisibility] = useState<CampaignMacro["visibility"]>("owner");
  const [moduleId, setModuleId] = useState("");
  const [moduleName, setModuleName] = useState("");
  const [moduleVersion, setModuleVersion] = useState("1.0.0");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    if (!campaignId) return;
    try {
      const [macroResult, moduleResult] = await Promise.all([
        api<{ macros: CampaignMacro[] }>(`/campaigns/${campaignId}/macros`),
        api<{ modules: CampaignModule[] }>(`/campaigns/${campaignId}/modules`),
      ]);
      setMacros(macroResult.macros);
      setModules(moduleResult.modules);
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Não foi possível carregar macros e módulos.",
      );
    }
  }, [campaignId, setError]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function createMacro() {
    if (!campaignId || !name.trim() || busy) return;
    setBusy(true);
    try {
      await api(`/campaigns/${campaignId}/macros`, {
        method: "POST",
        body: JSON.stringify({
          name: name.trim(),
          visibility: isManagerRole(role) ? visibility : "owner",
          hotbar_slot: Math.max(1, Math.min(10, slot)),
          commands: [{ type: "roll", formula: formula.trim(), label: name.trim() }],
        }),
      });
      setName("");
      await refresh();
      window.dispatchEvent(new Event("vtt:macros-changed"));
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível criar a macro.");
    } finally {
      setBusy(false);
    }
  }

  async function removeMacro(id: number) {
    try {
      await api(`/campaign-macros/${id}`, { method: "DELETE" });
      await refresh();
      window.dispatchEvent(new Event("vtt:macros-changed"));
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível excluir a macro.");
    }
  }

  async function createModule() {
    if (!campaignId || !moduleId.trim() || !moduleName.trim() || busy) return;
    setBusy(true);
    try {
      await api(`/campaigns/${campaignId}/modules`, {
        method: "POST",
        body: JSON.stringify({
          moduleId: moduleId.trim().toLowerCase(),
          name: moduleName.trim(),
          version: moduleVersion.trim(),
          manifest: {
            apiVersion: 1,
            id: moduleId.trim().toLowerCase(),
            name: moduleName.trim(),
            version: moduleVersion.trim(),
          },
          permissions: [],
          enabled: true,
        }),
      });
      setModuleId("");
      setModuleName("");
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível registrar o módulo.");
    } finally {
      setBusy(false);
    }
  }

  async function toggleModule(module: CampaignModule) {
    try {
      await api(`/campaign-modules/${module.id}`, {
        method: "PATCH",
        body: JSON.stringify({ enabled: !module.enabled }),
      });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível atualizar o módulo.");
    }
  }

  return (
    <section>
      <h3>Macros e módulos</h3>
      <p className="panel-hint">
        Macros usam comandos declarativos seguros; módulos de campanha guardam manifestos e
        permissões, sem executar JavaScript remoto.
      </p>
      <div className="editor-grid">
        <label>
          Nome da macro
          <input value={name} onChange={(event) => setName(event.target.value)} />
        </label>
        <label>
          Fórmula
          <input
            value={formula}
            onChange={(event) => setFormula(event.target.value)}
            placeholder="1d20+5"
          />
        </label>
        <label>
          Atalho 1–10
          <input
            type="number"
            min={1}
            max={10}
            value={slot}
            onChange={(event) => setSlot(Number(event.target.value))}
          />
        </label>
        {isManagerRole(role) && (
          <label>
            Visibilidade
            <select
              value={visibility}
              onChange={(event) => setVisibility(event.target.value as CampaignMacro["visibility"])}
            >
              <option value="owner">Só eu</option>
              <option value="campaign">Campanha</option>
              <option value="gm">Mestres</option>
            </select>
          </label>
        )}
      </div>
      <button
        className="primary"
        disabled={busy || !name.trim()}
        onClick={() => void createMacro()}
      >
        Criar macro
      </button>
      <div className="sheet-items">
        {macros.map((macro) => (
          <article key={macro.id}>
            <div>
              <h4>{macro.name}</h4>
              <small>
                Slot {macro.hotbar_slot ?? "—"} · {macro.visibility}
              </small>
            </div>
            <button className="danger" onClick={() => void removeMacro(macro.id)}>
              Excluir
            </button>
          </article>
        ))}
      </div>

      {isManagerRole(role) && (
        <details>
          <summary>Plugin API · módulos declarativos</summary>
          <div className="editor-grid">
            <label>
              ID
              <input
                value={moduleId}
                onChange={(event) => setModuleId(event.target.value)}
                placeholder="meu-modulo"
              />
            </label>
            <label>
              Nome
              <input value={moduleName} onChange={(event) => setModuleName(event.target.value)} />
            </label>
            <label>
              Versão
              <input
                value={moduleVersion}
                onChange={(event) => setModuleVersion(event.target.value)}
              />
            </label>
          </div>
          <button disabled={busy} onClick={() => void createModule()}>
            Registrar módulo
          </button>
          {modules.map((module) => (
            <p key={module.id}>
              <b>{module.name}</b> v{module.version} · {module.enabled ? "ativo" : "desativado"}{" "}
              <button onClick={() => void toggleModule(module)}>
                {module.enabled ? "Desativar" : "Ativar"}
              </button>
            </p>
          ))}
        </details>
      )}
    </section>
  );
}
