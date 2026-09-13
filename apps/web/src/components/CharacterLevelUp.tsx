import { useEffect, useMemo, useState } from "react";
import type { Actor } from "@vtt/core";
import { api } from "../lib/api";
import type { CatalogEntry, CatalogResults } from "../lib/catalog";
import {
  advanceCharacter,
  canMulticlass,
  multiclassRequirements,
  subclassMatchesClass,
  subclassStartLevel,
} from "../lib/characterProgression";
import { preparationTasks } from "../lib/characterPreparation";
import { useSession } from "../store/session";
import { CatalogPicker } from "./character-wizard/CatalogPicker";
import { Modal } from "./Modal";

export function CharacterLevelUp({ actor, onClose }: { actor: Actor; onClose: () => void }) {
  const { campaignId, ruleset, upsertActor, setError } = useSession();
  const [cls, setClass] = useState<CatalogEntry | null>(null);
  const [subclass, setSubclass] = useState<CatalogEntry | null>(null);
  const [currentClasses, setCurrentClasses] = useState<CatalogEntry[]>([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const ids = (actor.system.progression?.classes ?? [])
      .map((entry) => entry.classId)
      .filter((id): id is number => typeof id === "number");
    if (!ids.length && actor.system.preparation?.classId)
      ids.push(actor.system.preparation.classId);
    if (!campaignId || !ids.length) return;
    let active = true;
    void Promise.all(
      ids.map((id) =>
        api<CatalogResults>(
          `/catalog/classes?campaignId=${campaignId}&edition=${ruleset}&id=${id}&perPage=1`,
        ),
      ),
    )
      .then((results) => {
        if (!active) return;
        const entries = results.flatMap((result) => result.data);
        setCurrentClasses(entries);
        if (entries.length === 1) setClass(entries[0]);
      })
      .catch((error) =>
        setError(
          error instanceof Error ? error.message : "Não foi possível carregar a progressão atual.",
        ),
      );
    return () => {
      active = false;
    };
  }, [
    actor.id,
    actor.system.preparation?.classId,
    actor.system.progression?.classes,
    campaignId,
    ruleset,
    setError,
  ]);

  const existing = useMemo(() => {
    if (!cls) return null;
    return (
      actor.system.progression?.classes?.find(
        (entry) =>
          (entry.classId && entry.classId === cls.id) ||
          (entry.name === cls.name && (!entry.source || entry.source === cls.source)),
      ) ??
      (!actor.system.progression &&
      (actor.system.preparation?.classId === cls.id || actor.system.bio.class === cls.name)
        ? {
            classId: cls.id,
            name: cls.name,
            source: cls.source,
            level: actor.system.bio.level,
            hitDie: Number(cls.data.raw?.hd?.faces ?? actor.system.hitDice.die),
          }
        : null)
    );
  }, [actor.system, cls]);
  const multiclass = !!cls && !existing;
  const expectedCurrentClassCount =
    actor.system.progression?.classes.length ?? (actor.system.preparation?.classId ? 1 : 0);
  const currentClassesResolved =
    !multiclass ||
    (expectedCurrentClassCount > 0 && currentClasses.length === expectedCurrentClassCount);
  const newRequirements = cls ? multiclassRequirements(actor.system, cls) : [];
  const currentRequirements = multiclass
    ? currentClasses.flatMap((entry) => multiclassRequirements(actor.system, entry))
    : [];
  const requirementsOk =
    !multiclass ||
    (currentClassesResolved &&
      !!cls &&
      canMulticlass(actor.system, cls) &&
      currentClasses.every((entry) => canMulticlass(actor.system, entry)));
  const nextClassLevel = existing ? existing.level + 1 : 1;
  const chosenSubclassStart = subclass ? subclassStartLevel(subclass) : null;
  const progressionSubclasses =
    actor.system.progression?.subclasses ??
    (actor.system.progression?.subclass ? [actor.system.progression.subclass] : []);
  const hasSubclassForClass =
    !!cls &&
    progressionSubclasses.some(
      (entry) =>
        entry.className === cls.name &&
        (!entry.classSource || !cls.source || entry.classSource === cls.source),
    );

  async function apply() {
    if (!cls || busy || !requirementsOk) return;
    setBusy(true);
    setNotice("");
    try {
      const { system, tasks } = advanceCharacter(actor.system, cls, { multiclass, subclass });
      const review = multiclass ? tasks : [...preparationTasks(cls, system), ...tasks];
      if (system.preparation) {
        const completed = new Map(system.preparation.tasks.map((task) => [task.text, task.done]));
        system.preparation.tasks = [...new Set(review)].map((text) => ({
          text,
          done: completed.get(text) ?? false,
        }));
      } else {
        system.preparation = {
          classId: cls.id,
          source: cls.source,
          edition: ruleset,
          tasks: [...new Set(review)].map((text) => ({ text, done: false })),
        };
      }
      const response = await api<{ actor: Actor }>(`/actors/${actor.id}`, {
        method: "PATCH",
        body: JSON.stringify({ system, revision: actor.revision ?? 0 }),
      });
      upsertActor(response.actor);
      setNotice(
        `Nível ${response.actor.system.bio.level} aplicado. Revise as novas escolhas pendentes na ficha.`,
      );
      onClose();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível evoluir o personagem.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      wide
      title={`Evoluir ${actor.name} · nível ${actor.system.bio.level} → ${actor.system.bio.level + 1}`}
      onClose={() => {
        if (!busy) onClose();
      }}
    >
      <div className="character-wizard">
        <p>
          Escolha uma classe já existente para avançar nela ou outra classe para iniciar
          multiclasse. A operação calcula PV médios, dado de vida, bônus de proficiência e
          progressões explicitamente disponíveis no catálogo.
        </p>
        <CatalogPicker
          kind="classes"
          edition={ruleset}
          value={cls}
          onChange={(value) => {
            setClass(value);
            setSubclass(null);
          }}
        />
        {cls && (
          <section className="editor-section">
            <h3>{multiclass ? `Multiclasse · ${cls.name} 1` : `${cls.name} ${nextClassLevel}`}</h3>
            {multiclass && (
              <>
                <p>
                  Para multiclasse, os pré-requisitos da classe atual e da nova precisam ser
                  atendidos.
                </p>
                {[...currentRequirements, ...newRequirements].map((requirement, index) => (
                  <p key={index} className={requirement.ok ? "notice" : "notice notice--error"}>
                    {requirement.ok ? "✓" : "✕"} {requirement.text}
                  </p>
                ))}
                {!currentClassesResolved && (
                  <p className="notice notice--error">
                    Nem todas as classes atuais têm vínculo de catálogo suficiente para validar os
                    pré-requisitos. Vincule/recrie a progressão antes de adicionar multiclasse.
                  </p>
                )}
              </>
            )}
            {!hasSubclassForClass && nextClassLevel >= 1 && (
              <>
                <h4>Subclasse, quando este nível exigir</h4>
                <CatalogPicker
                  kind="subclasses"
                  classId={cls.id}
                  edition={ruleset}
                  value={subclass}
                  onChange={setSubclass}
                />
                {subclass && !subclassMatchesClass(subclass, cls) && (
                  <p className="notice notice--error">
                    {subclass.name} não pertence a {cls.name}.
                  </p>
                )}
                {subclass &&
                  chosenSubclassStart !== null &&
                  chosenSubclassStart > nextClassLevel && (
                    <p className="notice notice--error">
                      {subclass.name} começa no nível {chosenSubclassStart} de {cls.name}; escolha-a
                      apenas quando atingir esse nível.
                    </p>
                  )}
                <small>Se a classe ainda não escolhe subclasse neste nível, deixe em branco.</small>
              </>
            )}
          </section>
        )}
        {notice && <p role="status">{notice}</p>}
        <footer>
          <button disabled={busy} onClick={onClose}>
            Cancelar
          </button>
          <button
            className="primary"
            disabled={
              !cls ||
              busy ||
              !requirementsOk ||
              (!!subclass &&
                (!subclassMatchesClass(subclass, cls) ||
                  (chosenSubclassStart !== null && chosenSubclassStart > nextClassLevel)))
            }
            onClick={() => void apply()}
          >
            {busy ? "Aplicando…" : `Aplicar nível ${actor.system.bio.level + 1}`}
          </button>
        </footer>
      </div>
    </Modal>
  );
}
