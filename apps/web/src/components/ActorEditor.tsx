import { ActorSystemSchema, isValidDiceFormula, type Actor, type ActorSystem } from "@vtt/core";
import { useState, type FormEvent } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import { ActionsSection } from "./actor-editor/ActorEditorActions";
import { CurrencySection, SpellSlotsSection } from "./actor-editor/ActorEditorInventory";
import { ActorDocumentManager } from "./actor-editor/ActorDocumentManager";
import {
  EssentialsSection,
  ProficienciesSection,
  StorySection,
} from "./actor-editor/ActorEditorIdentity";
import { Icon } from "./Icon";
import { Modal } from "./Modal";

const SECTIONS = [
  "Essenciais",
  "Proficiências",
  "Equipamento",
  "Magias",
  "Ações",
  "Características",
  "História",
] as const;
type EditorSection = (typeof SECTIONS)[number];

export function ActorEditor({ actor, onClose }: { actor: Actor; onClose: () => void }) {
  const { ruleset, upsertActor } = useSession();
  const [system, setSystem] = useState(() => structuredClone(actor.system));
  const [name, setName] = useState(actor.name);
  const [section, setSection] = useState<EditorSection>("Essenciais");
  const [editRevision] = useState(actor.revision ?? 0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [dirty, setDirty] = useState(false);
  const [discard, setDiscard] = useState(false);

  function mutate(change: (system: ActorSystem) => void) {
    setDirty(true);
    setSystem((current) => {
      const next = structuredClone(current);
      change(next);
      return next;
    });
  }

  function changeName(nextName: string) {
    setName(nextName);
    setDirty(true);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const parsed = ActorSystemSchema.safeParse(system);
    if (
      !parsed.success ||
      system.hp.value > system.hp.max ||
      system.hp.value < 0 ||
      system.hp.max < 1
    ) {
      setError("Confira os valores da ficha. PV atual deve ficar entre zero e o máximo.");
      return;
    }
    if (
      system.actions.some(
        (action) =>
          (action.attackFormula && !isValidDiceFormula(action.attackFormula)) ||
          (action.damageFormula && !isValidDiceFormula(action.damageFormula)),
      )
    ) {
      setError("Use fórmulas válidas nas ações, como 1d20+7 ou 8d6.");
      return;
    }

    setBusy(true);
    setError("");
    try {
      const result = await api<{ actor: Actor }>(`/actors/${actor.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: name.trim(), system: parsed.data, revision: editRevision }),
      });
      upsertActor(result.actor);
      onClose();
    } catch (saveError) {
      setError(
        saveError instanceof Error
          ? saveError.message
          : "Não foi possível salvar. Suas alterações estão aqui.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      wide
      title="Editar ficha"
      onClose={() => {
        if (busy) return;
        if (dirty) setDiscard(true);
        else onClose();
      }}
    >
      <form onSubmit={save} className="actor-editor">
        <div className="editor-tabs">
          {SECTIONS.map((item) => (
            <button
              type="button"
              key={item}
              aria-pressed={section === item}
              onClick={() => setSection(item)}
            >
              {item}
            </button>
          ))}
        </div>
        <fieldset disabled={busy} className="editor-content">
          {section === "Essenciais" && (
            <EssentialsSection
              system={system}
              ruleset={ruleset}
              name={name}
              setName={changeName}
              mutate={mutate}
            />
          )}
          {section === "Proficiências" && <ProficienciesSection system={system} mutate={mutate} />}
          {section === "Equipamento" && (
            <>
              <ActorDocumentManager actorId={actor.id} kind="item" />
              <CurrencySection system={system} mutate={mutate} />
            </>
          )}
          {section === "Magias" && (
            <>
              <ActorDocumentManager actorId={actor.id} kind="spell" />
              <SpellSlotsSection system={system} mutate={mutate} />
            </>
          )}
          {section === "Ações" && (
            <ActionsSection system={system} documents={actor.documents} mutate={mutate} />
          )}
          {section === "Características" && (
            <ActorDocumentManager actorId={actor.id} kind="feature" />
          )}
          {section === "História" && <StorySection system={system} mutate={mutate} />}
        </fieldset>
        {error && (
          <p className="notice notice--error" role="alert">
            {error}
          </p>
        )}
        {discard && (
          <div className="notice">
            <p>Descartar as alterações desta edição?</p>
            <button type="button" onClick={() => setDiscard(false)}>
              Continuar editando
            </button>
            <button type="button" className="danger" onClick={onClose}>
              Descartar
            </button>
          </div>
        )}
        <footer className="editor-footer">
          <span>{dirty ? "Alterações ainda não salvas" : "Ficha atualizada"}</span>
          <button type="submit" className="primary" disabled={busy}>
            {busy ? "Salvando…" : "Salvar ficha"}
            <Icon name="check" size={16} />
          </button>
        </footer>
      </form>
    </Modal>
  );
}
