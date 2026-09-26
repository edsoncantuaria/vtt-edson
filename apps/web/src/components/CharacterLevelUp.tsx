import { useEffect, useMemo, useState } from "react";
import type { Ability, Actor } from "@vtt/core";
import { api, ApiError } from "../lib/api";
import {
  confirmActorAdvancement,
  previewActorAdvancement,
  type ActorAdvancementPreview,
  type ActorAdvancementRequest,
} from "../lib/advancement";
import type { CatalogEntry, CatalogResults } from "../lib/catalog";
import {
  advanceCharacter,
  classSpellSlotsAtLevel,
  canMulticlass,
  isManagerRole,
  multiclassRequirements,
  subclassMatchesClass,
  subclassStartLevel,
} from "@vtt/core";
import { useSession } from "../store/session";
import { CatalogPicker } from "./character-wizard/CatalogPicker";
import { Modal } from "./Modal";

export function CharacterLevelUp({ actor, onClose }: { actor: Actor; onClose: () => void }) {
  const { campaignId, ruleset, role, upsertActor, setError } = useSession();
  const [cls, setClass] = useState<CatalogEntry | null>(null);
  const [subclass, setSubclass] = useState<CatalogEntry | null>(null);
  const [currentClasses, setCurrentClasses] = useState<CatalogEntry[]>([]);
  const [subclassCandidates, setSubclassCandidates] = useState<CatalogEntry[] | null>(null);
  const [asiMode, setAsiMode] = useState<"" | "asi" | "feat">("");
  const [asiPrimary, setAsiPrimary] = useState<Ability | "">("");
  const [asiSecondary, setAsiSecondary] = useState<Ability | "">("");
  const [feat, setFeat] = useState<CatalogEntry | null>(null);
  const [spellChoice, setSpellChoice] = useState<CatalogEntry | null>(null);
  const [learnedSpells, setLearnedSpells] = useState<CatalogEntry[]>([]);
  const [forgottenIds, setForgottenIds] = useState<string[]>([]);
  const [preparedIds, setPreparedIds] = useState<string[]>(
    actor.system.spells.known.filter((spell) => spell.prepared).map((spell) => spell.id),
  );
  const [preparedSlugs, setPreparedSlugs] = useState<string[]>([]);
  const [overrideReason, setOverrideReason] = useState("");
  const [pending, setPending] = useState<{
    request: ActorAdvancementRequest;
    preview: ActorAdvancementPreview["preview"];
    notes: string[];
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const selectedClassId = cls?.id;

  useEffect(() => {
    if (!selectedClassId || !campaignId) {
      setSubclassCandidates([]);
      return;
    }
    const controller = new AbortController();
    setSubclassCandidates(null);
    void api<CatalogResults>(
      `/catalog/subclasses?campaignId=${campaignId}&edition=${ruleset}&classId=${selectedClassId}&perPage=100`,
      { signal: controller.signal },
    )
      .then((result) => setSubclassCandidates(result.data))
      .catch((error) => {
        if (!controller.signal.aborted)
          setError(
            error instanceof Error ? error.message : "Não foi possível consultar subclasses.",
          );
      });
    return () => controller.abort();
  }, [campaignId, selectedClassId, ruleset, setError]);

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
            hitDie: Number(cls.data.raw?.hd?.faces ?? actor.system.hitDice?.die ?? 8),
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

  const asiAtThisLevel = !!cls?.data.levelFeatures?.some(
    (feature) =>
      feature.level === nextClassLevel &&
      /ability score improvement|aumento no valor de habilidade/i.test(feature.name),
  );
  const slotPlan = cls ? classSpellSlotsAtLevel(cls, nextClassLevel) : null;
  const maxSpellLevel = Math.max(
    0,
    ...Object.keys(slotPlan ?? actor.system.spells.slots).map(Number),
  );
  const candidatesReady = subclassCandidates !== null;
  const abilityOptions: Ability[] = ["str", "dex", "con", "int", "wis", "cha"];
  const currentPrepared = actor.system.spells.known;

  function resetChoices() {
    setPending(null);
    setSubclass(null);
    setAsiMode("");
    setAsiPrimary("");
    setAsiSecondary("");
    setFeat(null);
    setLearnedSpells([]);
    setForgottenIds([]);
    setPreparedIds(
      actor.system.spells.known.filter((spell) => spell.prepared).map((spell) => spell.id),
    );
    setPreparedSlugs([]);
    setOverrideReason("");
    setNotice("");
  }

  async function preview() {
    if (!cls || busy || !requirementsOk || !candidatesReady) return;
    setBusy(true);
    setNotice("");
    try {
      const asi =
        asiMode === "asi" && asiPrimary && asiSecondary
          ? ({
              [asiPrimary]: asiPrimary === asiSecondary ? 2 : 1,
              ...(asiPrimary === asiSecondary ? {} : { [asiSecondary]: 1 }),
            } as Partial<Record<Ability, number>>)
          : undefined;
      const plan = advanceCharacter(actor.system, cls, {
        targetLevel: actor.system.bio.level + 1,
        multiclass,
        subclass,
        subclassCandidates: subclassCandidates ?? [],
        asi,
        feat: asiMode === "feat" ? feat : null,
        learnedSpells,
        forgetSpellIds: forgottenIds,
        preparedSpellIds: preparedIds,
        preparedSpellSlugs: preparedSlugs,
      });
      if (plan.conflicts.length || plan.choices.length) {
        setNotice([...plan.conflicts, ...plan.choices].join(" "));
        return;
      }
      const { system, tasks } = plan;
      const review = [...tasks];
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
      const request = {
        requestId: crypto.randomUUID(),
        revision: actor.revision ?? 0,
        classId: cls.id,
        targetLevel: actor.system.bio.level + 1,
        system,
        ...(subclass ? { subclassId: subclass.id } : {}),
        ...(asiMode === "feat" && feat ? { featId: feat.id } : {}),
        ...(asi ? { asi } : {}),
        ...(overrideReason.trim() ? { overrideReason: overrideReason.trim() } : {}),
      };
      const response = await previewActorAdvancement(actor.id, request);
      setPending({ request, preview: response.preview, notes: tasks });
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Não foi possível gerar a prévia da evolução.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!pending || busy) return;
    setBusy(true);
    try {
      const response = await confirmActorAdvancement(actor.id, pending.request);
      upsertActor(response.actor);
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        // The preview was based on an obsolete revision. Refresh the sheet,
        // discard the old proposal and require another server preview.
        setPending(null);
        try {
          const refreshed = await api<{ actor: Actor }>(`/actors/${actor.id}`);
          upsertActor(refreshed.actor);
          setNotice("A ficha mudou durante a revisão. Confira as escolhas e gere outra prévia.");
        } catch (refreshError) {
          setError(
            refreshError instanceof Error
              ? refreshError.message
              : "A ficha mudou. Reabra a evolução para atualizar os dados.",
          );
        }
      } else {
        // A network timeout is ambiguous: retain the same requestId so a retry
        // cannot apply the advancement twice if the first request succeeded.
        setError(error instanceof Error ? error.message : "Não foi possível confirmar a evolução.");
      }
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
        {!pending && (
          <>
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
                resetChoices();
              }}
            />
            {cls && (
              <section className="editor-section">
                <h3>
                  {multiclass ? `Multiclasse · ${cls.name} 1` : `${cls.name} ${nextClassLevel}`}
                </h3>
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
                        Nem todas as classes atuais têm vínculo de catálogo suficiente para validar
                        os pré-requisitos. Vincule/recrie a progressão antes de adicionar
                        multiclasse.
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
                          {subclass.name} começa no nível {chosenSubclassStart} de {cls.name};
                          escolha-a apenas quando atingir esse nível.
                        </p>
                      )}
                    <small>
                      Se a classe ainda não escolhe subclasse neste nível, deixe em branco.
                    </small>
                  </>
                )}
                {!candidatesReady && <p role="status">Consultando as opções de subclasse…</p>}
                {asiAtThisLevel && (
                  <section className="editor-section">
                    <h4>Aumento de atributo ou talento</h4>
                    <label>
                      <input
                        type="radio"
                        name="advancement-asi"
                        checked={asiMode === "asi"}
                        onChange={() => {
                          setAsiMode("asi");
                          setFeat(null);
                        }}
                      />{" "}
                      Aumentar atributos
                    </label>
                    <label>
                      <input
                        type="radio"
                        name="advancement-asi"
                        checked={asiMode === "feat"}
                        onChange={() => {
                          setAsiMode("feat");
                          setAsiPrimary("");
                          setAsiSecondary("");
                        }}
                      />{" "}
                      Escolher talento
                    </label>
                    {asiMode === "asi" && (
                      <div className="wizard-options">
                        {([asiPrimary, asiSecondary] as const).map((selected, index) => (
                          <label key={index}>
                            Atributo {index + 1}
                            <select
                              value={selected}
                              onChange={(event) =>
                                (index === 0 ? setAsiPrimary : setAsiSecondary)(
                                  event.target.value as Ability | "",
                                )
                              }
                            >
                              <option value="">Escolher...</option>
                              {abilityOptions
                                .filter(
                                  (key) =>
                                    actor.system.abilities[key].score +
                                      (selected === key && asiPrimary === asiSecondary ? 2 : 1) <=
                                    20,
                                )
                                .map((key) => (
                                  <option key={key} value={key}>
                                    {key.toUpperCase()} · {actor.system.abilities[key].score}
                                  </option>
                                ))}
                            </select>
                          </label>
                        ))}
                        <small>
                          O mesmo atributo duas vezes concede +2; dois atributos diferentes recebem
                          +1 cada.
                        </small>
                      </div>
                    )}
                    {asiMode === "feat" && (
                      <CatalogPicker
                        kind="feats"
                        edition={ruleset}
                        value={feat}
                        onChange={setFeat}
                      />
                    )}
                  </section>
                )}
                <section className="editor-section">
                  <h4>Magias da progressão</h4>
                  <p>
                    Espaços disponíveis na prévia:{" "}
                    {Object.entries(slotPlan ?? actor.system.spells.slots)
                      .map(([level, slot]) => `${level}º: ${slot.max}`)
                      .join(" · ") || "Nenhum"}
                    .
                  </p>
                  <CatalogPicker
                    kind="spells"
                    edition={ruleset}
                    classId={cls.id}
                    maxLevel={maxSpellLevel}
                    value={spellChoice}
                    onChange={setSpellChoice}
                  />
                  <button
                    disabled={
                      !spellChoice || learnedSpells.some((spell) => spell.id === spellChoice.id)
                    }
                    onClick={() => {
                      if (spellChoice) setLearnedSpells((current) => [...current, spellChoice]);
                      setSpellChoice(null);
                    }}
                  >
                    Adicionar magia selecionada
                  </button>
                  {learnedSpells.map((spell) => (
                    <label className="check-label" key={spell.id}>
                      {spell.name} · {spell.level ?? 0}º
                      <input
                        type="checkbox"
                        checked={preparedSlugs.includes(spell.slug)}
                        onChange={(event) =>
                          setPreparedSlugs((current) =>
                            event.target.checked
                              ? [...current, spell.slug]
                              : current.filter((slug) => slug !== spell.slug),
                          )
                        }
                      />{" "}
                      Preparar
                      <button
                        onClick={() => {
                          setLearnedSpells((current) =>
                            current.filter((item) => item.id !== spell.id),
                          );
                          setPreparedSlugs((current) =>
                            current.filter((slug) => slug !== spell.slug),
                          );
                        }}
                      >
                        Remover
                      </button>
                    </label>
                  ))}
                  {currentPrepared.map((spell) => (
                    <label className="check-label" key={spell.id}>
                      {spell.name}
                      <input
                        type="checkbox"
                        disabled={forgottenIds.includes(spell.id)}
                        checked={!forgottenIds.includes(spell.id) && preparedIds.includes(spell.id)}
                        onChange={(event) =>
                          setPreparedIds((current) =>
                            event.target.checked
                              ? [...current, spell.id]
                              : current.filter((id) => id !== spell.id),
                          )
                        }
                      />{" "}
                      Preparar
                      <input
                        type="checkbox"
                        checked={forgottenIds.includes(spell.id)}
                        onChange={(event) =>
                          setForgottenIds((current) =>
                            event.target.checked
                              ? [...current, spell.id]
                              : current.filter((id) => id !== spell.id),
                          )
                        }
                      />{" "}
                      Substituir/esquecer
                    </label>
                  ))}
                </section>
                {multiclass &&
                  actor.system.spells.slots &&
                  Object.keys(actor.system.spells.slots).length > 0 && (
                    <p className="notice">
                      Multiclasse conjuradora pode exigir revisão dos espaços pelo mestre; eles não
                      serão recalculados silenciosamente.
                    </p>
                  )}
                {isManagerRole(role) && (
                  <label>
                    Justificativa de revisão de regra (se necessária)
                    <textarea
                      rows={2}
                      value={overrideReason}
                      onChange={(event) => setOverrideReason(event.target.value)}
                      placeholder="Registre a decisão do mestre com no mínimo 20 caracteres."
                    />
                  </label>
                )}
              </section>
            )}
          </>
        )}
        {pending && (
          <section className="editor-section" aria-label="Prévia da evolução">
            <h3>Prévia confirmada pelo servidor</h3>
            <dl className="wizard-review">
              <dt>Nível</dt>
              <dd>{pending.preview.level.join(" → ")}</dd>
              <dt>PV máximos</dt>
              <dd>{pending.preview.hp.join(" → ")}</dd>
              <dt>Bônus de proficiência</dt>
              <dd>{pending.preview.proficiency.join(" → ")}</dd>
            </dl>
            {pending.notes.map((note, index) => (
              <p key={index}>{note}</p>
            ))}
            <p>
              Confirmar irá atualizar a ficha e registrar esta evolução no histórico. Cancelar não
              altera nenhum recurso.
            </p>
          </section>
        )}
        {notice && <p role="status">{notice}</p>}
        <footer>
          <button disabled={busy} onClick={onClose}>
            Cancelar
          </button>
          {pending && (
            <button disabled={busy} onClick={() => setPending(null)}>
              Editar escolhas
            </button>
          )}
          <button
            className="primary"
            disabled={
              (!pending && (!cls || !candidatesReady)) ||
              busy ||
              !requirementsOk ||
              (!pending &&
                asiAtThisLevel &&
                (asiMode === "" ||
                  (asiMode === "asi" && (!asiPrimary || !asiSecondary)) ||
                  (asiMode === "feat" && !feat))) ||
              (!!subclass &&
                (!subclassMatchesClass(subclass, cls) ||
                  (chosenSubclassStart !== null && chosenSubclassStart > nextClassLevel)))
            }
            onClick={() => void (pending ? confirm() : preview())}
          >
            {busy
              ? "Processando…"
              : pending
                ? `Confirmar nível ${actor.system.bio.level + 1}`
                : "Revisar prévia"}
          </button>
        </footer>
      </div>
    </Modal>
  );
}
