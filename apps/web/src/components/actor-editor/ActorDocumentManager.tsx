import type { Actor, ActorDocument } from "@vtt/core";
import { useState } from "react";
import { api } from "../../lib/api";
import { useSession } from "../../store/session";
import { Icon } from "../Icon";

type Kind = ActorDocument["kind"];

function value(document: ActorDocument, key: string): unknown {
  return document.overrides[key] ?? document.data[key];
}

export function ActorDocumentManager({ actorId, kind }: { actorId: number; kind: Kind }) {
  const actor = useSession((state) => state.actors.find((item) => item.id === actorId));
  const upsertActor = useSession((state) => state.upsertActor);
  const setError = useSession((state) => state.setError);
  const [busy, setBusy] = useState(false);
  const documents = (actor?.documents ?? []).filter((document) => document.kind === kind);

  async function create() {
    if (busy) return;
    const labels = {
      item: "Novo item",
      spell: "Nova magia",
      feature: "Nova característica",
    } as const;
    const base =
      kind === "item"
        ? { id: crypto.randomUUID(), name: labels[kind], quantity: 1, equipped: false }
        : kind === "spell"
          ? { id: crypto.randomUUID(), name: labels[kind], level: 1, prepared: false }
          : { id: crypto.randomUUID(), name: labels[kind], description: "" };
    setBusy(true);
    try {
      const result = await api<{ actor: Actor }>(`/actors/${actorId}/documents`, {
        method: "POST",
        body: JSON.stringify({ kind, name: labels[kind], data: base }),
      });
      upsertActor(result.actor);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível criar o documento.");
    } finally {
      setBusy(false);
    }
  }

  async function patch(document: ActorDocument, payload: Record<string, unknown>) {
    if (busy) return;
    setBusy(true);
    try {
      const result = await api<{ actor: Actor }>(`/actor-documents/${document.id}`, {
        method: "PATCH",
        body: JSON.stringify(payload),
      });
      upsertActor(result.actor);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível atualizar o documento.");
    } finally {
      setBusy(false);
    }
  }

  function override(document: ActorDocument, key: string, next: unknown) {
    return patch(document, { overrides: { ...document.overrides, [key]: next } });
  }

  async function remove(document: ActorDocument) {
    if (busy) return;
    setBusy(true);
    try {
      const result = await api<{ actor: Actor }>(`/actor-documents/${document.id}`, {
        method: "DELETE",
      });
      upsertActor(result.actor);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível remover o documento.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <p className="panel-hint">
        Estes registros são documentos canônicos. Conteúdo do compêndio mantém vínculo com a origem;
        alterações locais ficam como overrides e sobrevivem a atualizações do catálogo.
      </p>
      {documents.map((document) => (
        <section className="editor-item" key={document.id}>
          <div className="editor-item__head">
            <label>
              Nome
              <input
                defaultValue={document.name}
                disabled={busy}
                onBlur={(event) => {
                  if (event.target.value.trim() && event.target.value !== document.name)
                    void patch(document, { name: event.target.value.trim() });
                }}
              />
            </label>
            <span>
              {document.source ?? "Local"}
              {document.catalog_entry_id ? " · vinculado ao compêndio" : ""}
            </span>
            <button
              type="button"
              className="icon-button danger"
              disabled={busy}
              aria-label={`Remover ${document.name}`}
              onClick={() => void remove(document)}
            >
              <Icon name="trash" size={16} />
            </button>
          </div>
          {kind === "item" && (
            <div className="editor-grid">
              <label>
                Quantidade
                <input
                  type="number"
                  min={1}
                  max={10000}
                  value={document.quantity}
                  disabled={busy}
                  onChange={(event) =>
                    void patch(document, { quantity: Math.max(1, Number(event.target.value)) })
                  }
                />
              </label>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={document.equipped}
                  disabled={busy}
                  onChange={(event) => void patch(document, { equipped: event.target.checked })}
                />
                Equipado
              </label>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={document.attuned}
                  disabled={busy}
                  onChange={(event) => void patch(document, { attuned: event.target.checked })}
                />
                Sintonizado
              </label>
            </div>
          )}
          {kind === "spell" && (
            <div className="editor-grid">
              <label>
                Círculo
                <input
                  type="number"
                  min={0}
                  max={9}
                  value={Number(value(document, "level") ?? 0)}
                  disabled={busy}
                  onChange={(event) => void override(document, "level", Number(event.target.value))}
                />
              </label>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={document.prepared}
                  disabled={busy}
                  onChange={(event) => void patch(document, { prepared: event.target.checked })}
                />
                Preparada
              </label>
            </div>
          )}
          <label>
            Descrição local
            <textarea
              rows={3}
              defaultValue={String(value(document, "description") ?? "")}
              disabled={busy}
              onBlur={(event) => void override(document, "description", event.target.value)}
            />
          </label>
          {document.charges && (
            <p>
              Cargas:{" "}
              <b>
                {document.charges.value}/{document.charges.max}
              </b>{" "}
              · recuperação {document.charges.reset}
              {document.charges.recoveryFormula ? ` (${document.charges.recoveryFormula})` : ""}
            </p>
          )}
        </section>
      ))}
      <button type="button" disabled={busy} onClick={() => void create()}>
        <Icon name="plus" size={16} />
        {kind === "item"
          ? "Adicionar item local"
          : kind === "spell"
            ? "Adicionar magia local"
            : "Adicionar característica local"}
      </button>
    </>
  );
}
