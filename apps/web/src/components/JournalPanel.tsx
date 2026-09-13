import { useCallback, useEffect, useState } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import { Icon } from "./Icon";

type Entry = {
  id: number;
  title: string;
  body: string;
  visibility: "all" | "gm" | "selected";
  updated_at: string;
  scene_id: number | null;
  catalog_entry_id: number | null;
  folder: string | null;
  metadata: JournalMetadata | null;
  attachments: { type: "ref" | "url"; label?: string; ref?: string; url?: string }[] | null;
  shared_user_ids: number[] | null;
};

type JournalMetadata = Record<string, unknown> & {
  book?: { name?: string; source?: string };
  chapter?: { name?: string };
  scene?: { name?: string };
  pins?: unknown[];
  encounters?: unknown[];
};

type ShareTarget = { id: number; name: string };

function attachmentHref(attachment: NonNullable<Entry["attachments"]>[number]) {
  if (attachment.type === "url") return attachment.url;
  const match = attachment.ref?.match(/^catalog:(\d+):map:(\d+)$/);
  if (match) return `/api/catalog-media/${match[1]}/map-${match[2]}`;
  const art = attachment.ref?.match(/^catalog:(\d+):(art|token)$/);
  return art ? `/api/catalog-media/${art[1]}/${art[2]}` : undefined;
}

export function JournalPanel() {
  const { campaignId, sceneId, role, setError } = useSession();
  const [entries, setEntries] = useState<Entry[]>([]);
  const [selected, setSelected] = useState<Entry | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [scope, setScope] = useState("all");
  const [shareTargets, setShareTargets] = useState<ShareTarget[]>([]);
  const [attachmentUrl, setAttachmentUrl] = useState("");
  const [attachmentRef, setAttachmentRef] = useState("");
  const saved = entries.find((entry) => entry.id === selected?.id);
  const dirty = !!selected && !!saved && JSON.stringify(selected) !== JSON.stringify(saved);
  const gm = role === "gm";
  const visibleEntries = entries.filter((entry) =>
    scope === "scene"
      ? entry.scene_id === sceneId
      : scope === "gm"
        ? entry.visibility === "gm"
        : scope === "shared"
          ? entry.visibility !== "gm"
          : true,
  );
  const folders = visibleEntries.reduce<Record<string, Entry[]>>((groups, entry) => {
    const folder = entry.folder?.trim() || "Sem pasta";
    (groups[folder] ??= []).push(entry);
    return groups;
  }, {});
  const load = useCallback(async () => {
    if (!campaignId) return;
    setLoading(true);
    try {
      const result = await api<{ entries: Entry[]; shareTargets?: ShareTarget[] }>(
        `/campaigns/${campaignId}/journals`,
      );
      setEntries(result.entries);
      setShareTargets(result.shareTargets ?? []);
      setSelected(
        (current) =>
          result.entries.find((entry) => entry.id === current?.id) ?? result.entries[0] ?? null,
      );
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível abrir o diário.");
    } finally {
      setLoading(false);
    }
  }, [campaignId, setError]);
  useEffect(() => {
    void load();
  }, [load]);
  async function save(entry: Entry) {
    setBusy(true);
    try {
      const result = await api<{ entry: Entry }>(`/journals/${entry.id}`, {
        method: "PATCH",
        body: JSON.stringify({
          title: entry.title,
          body: entry.body,
          visibility: entry.visibility,
          scene_id: entry.scene_id,
          catalog_entry_id: entry.catalog_entry_id,
          folder: entry.folder,
          metadata: entry.metadata ?? {},
          attachments: entry.attachments ?? [],
          shared_user_ids: entry.shared_user_ids ?? [],
          updated_at: entry.updated_at,
        }),
      });
      setEntries((items) =>
        items.map((item) => (item.id === result.entry.id ? result.entry : item)),
      );
      setSelected(result.entry);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível salvar o diário.");
    } finally {
      setBusy(false);
    }
  }
  async function create() {
    if (!campaignId) return;
    setBusy(true);
    try {
      const result = await api<{ entry: Entry }>(`/campaigns/${campaignId}/journals`, {
        method: "POST",
        body: JSON.stringify({
          title: "Nova anotação",
          body: "",
          visibility: "gm",
          scene_id: sceneId,
          folder: "Diário",
          metadata: {},
          attachments: [],
          shared_user_ids: [],
        }),
      });
      setEntries((items) => [result.entry, ...items]);
      setSelected(result.entry);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível criar a anotação.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="journal-panel">
      <aside>
        <label>
          Mostrar notas
          <select value={scope} onChange={(event) => setScope(event.target.value)}>
            <option value="all">Toda a campanha</option>
            <option value="scene">Cena atual</option>
            {gm && <option value="gm">Só mestre</option>}
            <option value="shared">Compartilhadas</option>
          </select>
        </label>
        <button disabled={busy || loading || dirty} onClick={() => void load()}>
          Atualizar diário
        </button>
        {gm && (
          <button className="primary" disabled={busy || dirty} onClick={() => void create()}>
            <Icon name="plus" size={15} /> Nova nota
          </button>
        )}
        {loading ? (
          <p>Carregando…</p>
        ) : (
          Object.entries(folders)
            .sort(([a], [b]) => a.localeCompare(b, "pt-BR"))
            .map(([folder, items]) => (
              <div key={folder} className="journal-folder">
                <strong>{folder}</strong>
                {items.map((entry) => (
                  <button
                    key={entry.id}
                    disabled={busy || dirty}
                    aria-pressed={selected?.id === entry.id}
                    onClick={() => setSelected(entry)}
                  >
                    <b>{entry.title}</b>
                    <small>
                      {entry.visibility === "gm"
                        ? "Só mestre"
                        : entry.visibility === "selected"
                          ? "Jogadores escolhidos"
                          : "Grupo"}
                    </small>
                  </button>
                ))}
              </div>
            ))
        )}
      </aside>
      <section>
        {selected ? (
          <>
            <input
              aria-label="Título da anotação"
              value={selected.title}
              readOnly={!gm}
              onChange={(event) => setSelected({ ...selected, title: event.target.value })}
            />
            <textarea
              aria-label="Conteúdo da anotação"
              value={selected.body}
              readOnly={!gm}
              onChange={(event) => setSelected({ ...selected, body: event.target.value })}
              placeholder="Registre descobertas, pistas e lembranças da aventura…"
            />
            {!!selected.metadata?.book && (
              <small>
                Livro: {selected.metadata.book.name ?? selected.metadata.book.source} · Capítulo:{" "}
                {selected.metadata?.chapter?.name ?? "—"}
                {selected.metadata?.scene?.name ? ` · Cena: ${selected.metadata.scene.name}` : ""}
              </small>
            )}
            {!!selected.metadata?.pins?.length && (
              <small>{selected.metadata.pins.length} pins de mapa vinculados à preparação.</small>
            )}
            {!!selected.metadata?.encounters?.length && (
              <small>
                {selected.metadata.encounters.length} encontros/metadados upstream para revisar.
              </small>
            )}
            {!!selected.attachments?.length && (
              <div>
                {selected.attachments.map((attachment, index) => {
                  const href = attachmentHref(attachment);
                  return (
                    <p key={`${attachment.ref ?? attachment.url}-${index}`}>
                      {href ? (
                        <a href={href} target="_blank" rel="noreferrer">
                          {attachment.label || attachment.ref || "Anexo"}
                        </a>
                      ) : (
                        <span>{attachment.label || "Anexo inválido"}</span>
                      )}
                      {gm && (
                        <button
                          disabled={busy}
                          onClick={() =>
                            setSelected({
                              ...selected,
                              attachments: (selected.attachments ?? []).filter(
                                (_, i) => i !== index,
                              ),
                            })
                          }
                        >
                          Remover
                        </button>
                      )}
                    </p>
                  );
                })}
              </div>
            )}
            {gm && (
              <footer>
                {dirty && (
                  <>
                    <p role="status">Alterações ainda não salvas.</p>
                    <button disabled={busy} onClick={() => setSelected(saved ?? null)}>
                      Descartar alterações
                    </button>
                  </>
                )}
                <label>
                  <input
                    type="checkbox"
                    checked={selected.scene_id === sceneId && sceneId != null}
                    onChange={(event) =>
                      setSelected({ ...selected, scene_id: event.target.checked ? sceneId : null })
                    }
                  />{" "}
                  Vincular à cena atual
                </label>
                <label>
                  Pasta
                  <input
                    value={selected.folder ?? ""}
                    maxLength={120}
                    onChange={(event) =>
                      setSelected({ ...selected, folder: event.target.value || null })
                    }
                    placeholder="Ex.: Aventuras/LMoP"
                  />
                </label>
                <label>
                  Compartilhar
                  <select
                    value={selected.visibility}
                    onChange={(event) =>
                      setSelected({
                        ...selected,
                        visibility: event.target.value as Entry["visibility"],
                        shared_user_ids:
                          event.target.value === "selected" ? (selected.shared_user_ids ?? []) : [],
                      })
                    }
                  >
                    <option value="gm">Só mestre</option>
                    <option value="all">Todo o grupo</option>
                    <option value="selected">Jogadores escolhidos</option>
                  </select>
                </label>
                {selected.visibility === "selected" && (
                  <div>
                    {shareTargets.map((player) => (
                      <label className="check-label" key={player.id}>
                        <input
                          type="checkbox"
                          checked={(selected.shared_user_ids ?? []).includes(player.id)}
                          onChange={(event) =>
                            setSelected({
                              ...selected,
                              shared_user_ids: event.target.checked
                                ? [...(selected.shared_user_ids ?? []), player.id]
                                : (selected.shared_user_ids ?? []).filter((id) => id !== player.id),
                            })
                          }
                        />
                        {player.name}
                      </label>
                    ))}
                  </div>
                )}
                <label>
                  Anexo por URL HTTPS
                  <input
                    value={attachmentUrl}
                    onChange={(event) => setAttachmentUrl(event.target.value)}
                    placeholder="https://…"
                  />
                </label>
                <button
                  disabled={busy || !attachmentUrl.trim().startsWith("https://")}
                  onClick={() => {
                    setSelected({
                      ...selected,
                      attachments: [
                        ...(selected.attachments ?? []),
                        { type: "url", label: "Handout", url: attachmentUrl.trim() },
                      ],
                    });
                    setAttachmentUrl("");
                  }}
                >
                  Adicionar handout
                </button>
                <label>
                  Referência do catálogo
                  <input
                    value={attachmentRef}
                    onChange={(event) => setAttachmentRef(event.target.value)}
                    placeholder="catalog:123:art ou catalog:123:map:0"
                  />
                </label>
                <button
                  disabled={
                    busy || !/^catalog:\d+:(?:map:\d+|art|token)$/.test(attachmentRef.trim())
                  }
                  onClick={() => {
                    setSelected({
                      ...selected,
                      attachments: [
                        ...(selected.attachments ?? []),
                        { type: "ref", label: "Referência do catálogo", ref: attachmentRef.trim() },
                      ],
                    });
                    setAttachmentRef("");
                  }}
                >
                  Adicionar referência
                </button>
                <button
                  className="primary"
                  disabled={
                    busy ||
                    !dirty ||
                    !selected.title.trim() ||
                    (selected.visibility === "selected" && !selected.shared_user_ids?.length)
                  }
                  onClick={() => void save(selected)}
                >
                  Salvar
                </button>
              </footer>
            )}
          </>
        ) : (
          <p className="panel-hint">
            {gm
              ? "Crie a primeira anotação da campanha."
              : "O mestre ainda não compartilhou anotações."}
          </p>
        )}
      </section>
    </div>
  );
}
