import { useEffect, useMemo, useState } from "react";
import { ABILITY_LABELS, type Ability, type Actor } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import type { CatalogEntry } from "../lib/catalog";
import { preparationTasks, spellLimits, spellSelectionIssues } from "../lib/characterPreparation";
import { buildCharacter, STANDARD_SCORES } from "../lib/characterBuilder";
import {
  combineStartingEquipmentPlans,
  resolveStartingEquipment,
  startingEquipmentPlan,
} from "../lib/startingEquipment";
import {
  AbilitiesStep,
  ClassStep,
  EquipmentSpellsStep,
  IdentityStep,
  OriginStep,
  ReviewStep,
} from "./character-wizard/CharacterWizardSteps";
import type { AbilityOption, SkillChoice } from "./character-wizard/types";
const ABILITIES = Object.keys(ABILITY_LABELS) as Ability[];
const steps = ["Identidade", "Classe", "Origem", "Atributos", "Equipamento e magias", "Revisão"];
export function CharacterWizard({ onClose }: { onClose: () => void }) {
  const { ruleset, campaignId, user, upsertActor, setSelectedActorId } = useSession();
  const draftKey = `vtt-character-draft:${user?.id}:${campaignId}:${ruleset}`;
  const [restored, setRestored] = useState(false);
  const [draftNotice, setDraftNotice] = useState("");
  const [step, setStep] = useState(0);
  const [name, setName] = useState("");
  const [cls, setClass] = useState<CatalogEntry | null>(null);
  const [race, setRace] = useState<CatalogEntry | null>(null);
  const [bg, setBg] = useState<CatalogEntry | null>(null);
  const [prepared, setPrepared] = useState<string[]>([]);
  const [equipment, setEquipment] = useState<CatalogEntry[]>([]);
  const [spells, setSpells] = useState<CatalogEntry[]>([]);
  const [equipmentSelections, setEquipmentSelections] = useState<Record<string, string>>({});
  const [itemChoice, setItemChoice] = useState<CatalogEntry | null>(null);
  const [spellChoice, setSpellChoice] = useState<CatalogEntry | null>(null);
  const [scores, setScores] = useState<Record<Ability, number>>({
    str: 15,
    dex: 14,
    con: 13,
    int: 12,
    wis: 10,
    cha: 8,
  });
  const [scoreMethod, setScoreMethod] = useState<"standard" | "points">("standard");
  const [abilityOption, setAbilityOption] = useState(0);
  const [chosenAbilities, setChosenAbilities] = useState<Ability[]>([]);
  const [skills, setSkills] = useState<string[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    try {
      const raw = localStorage.getItem(draftKey);
      if (raw) {
        const draft = JSON.parse(raw);
        if (draft.version === 1) {
          setName(draft.name);
          setClass(draft.cls);
          setRace(draft.race);
          setBg(draft.bg);
          setScores(draft.scores);
          setScoreMethod(draft.scoreMethod);
          setSkills(draft.skills);
          setEquipment(draft.equipment);
          setEquipmentSelections(draft.equipmentSelections ?? {});
          setSpells(draft.spells);
          setPrepared(draft.prepared ?? []);
          setStep(0);
          setDraftNotice(
            "Rascunho recuperado. Revise as etapas e confirme novamente os bônus de origem.",
          );
        }
      }
    } catch {
      setDraftNotice("Não foi possível recuperar o rascunho salvo.");
    }
    setRestored(true);
  }, [draftKey]);
  useEffect(() => {
    if (!restored) return;
    const timer = setTimeout(() => {
      try {
        localStorage.setItem(
          draftKey,
          JSON.stringify({
            version: 1,
            name,
            cls,
            race,
            bg,
            scores,
            scoreMethod,
            skills,
            equipment,
            equipmentSelections,
            spells,
            prepared,
          }),
        );
      } catch {
        setDraftNotice("O navegador não conseguiu salvar o rascunho. Mantenha esta página aberta.");
      }
    }, 300);
    return () => clearTimeout(timer);
  }, [
    restored,
    draftKey,
    name,
    cls,
    race,
    bg,
    scores,
    scoreMethod,
    skills,
    equipment,
    equipmentSelections,
    spells,
    prepared,
  ]);
  const origin = ruleset === "5e-2024" ? bg : race;
  const abilityOptions = (
    Array.isArray(origin?.data.raw?.ability) ? origin.data.raw.ability : []
  ) as AbilityOption[];
  const ability = abilityOptions[abilityOption] ?? {};
  const fixed = Object.fromEntries(
    ABILITIES.filter((k) => typeof ability[k] === "number").map((k) => [k, ability[k]]),
  ) as Partial<Record<Ability, number>>;
  const choice = ability.choose?.weighted;
  const simpleChoice = ability.choose;
  const allowed = useMemo<Ability[]>(
    () => choice?.from ?? simpleChoice?.from ?? ABILITIES,
    [choice?.from, simpleChoice?.from],
  );
  const needsChoice = !!ability.choose;
  const weights = useMemo<number[]>(
    () =>
      choice?.weights ??
      (simpleChoice ? Array(simpleChoice.count ?? 1).fill(simpleChoice.amount ?? 1) : []),
    [choice?.weights, simpleChoice],
  );
  useEffect(() => {
    setAbilityOption(0);
  }, [origin?.id]);
  useEffect(() => {
    setChosenAbilities(allowed.slice(0, weights.length) as Ability[]);
  }, [origin?.id, abilityOption, allowed, weights.length]);
  const chosenBonus = needsChoice
    ? Object.fromEntries(chosenAbilities.map((key, index) => [key, weights[index] ?? 0]))
    : {};
  const bonuses = { ...fixed, ...chosenBonus };
  const skillChoice = cls?.data.raw.startingProficiencies?.skills?.find(
    (value: Record<string, unknown>) => value.choose,
  )?.choose as SkillChoice | undefined;
  const costs: Record<number, number> = { 8: 0, 9: 1, 10: 2, 11: 3, 12: 4, 13: 5, 14: 7, 15: 9 };
  const points = Object.values(scores).reduce((sum, score) => sum + (costs[score] ?? 100), 0);
  const validScores =
    scoreMethod === "standard"
      ? Object.values(scores)
          .sort((a, b) => b - a)
          .join(",") === STANDARD_SCORES.join(",")
      : points <= 27;
  const validBonus =
    !needsChoice ||
    (chosenAbilities.length === weights.length &&
      new Set(chosenAbilities).size === weights.length &&
      chosenAbilities.every((key) => allowed.includes(key)));
  const validSkills = !skillChoice || skills.length === skillChoice.count;
  const equipmentPlan = combineStartingEquipmentPlans(
    cls ? startingEquipmentPlan(cls) : { groups: [], structured: false },
    bg ? startingEquipmentPlan(bg) : { groups: [], structured: false },
  );
  const equipmentResolution = resolveStartingEquipment(
    equipmentPlan,
    equipmentSelections,
    equipment,
  );
  const system =
    cls && race && bg ? buildCharacter(ruleset, cls, race, bg, scores, bonuses, skills) : null;
  if (system) {
    system.inventory = equipmentPlan.structured
      ? equipmentResolution.inventory
      : equipment.map((item) => ({
          id: item.slug,
          slug: item.slug,
          name: item.name,
          quantity: 1,
          equipped: false,
          description: String(item.data.description ?? ""),
        }));
    if (equipmentPlan.structured) {
      system.currency.gp += equipmentResolution.currencyGp;
      system.currency.cp += equipmentResolution.currencyCp;
    }
    system.spells.known = spells.map((spell) => ({
      id: spell.slug,
      slug: spell.slug,
      name: spell.name,
      level: spell.level ?? 0,
      prepared: prepared.includes(spell.slug),
      description: String(spell.data.description ?? ""),
    }));
  }
  const limits = cls && system ? spellLimits(cls, system) : null;
  const tasks = cls && system ? preparationTasks(cls, system, bg) : [];
  const spellIssues = cls && system ? spellSelectionIssues(cls, system) : [];
  if (cls)
    for (const spell of spells)
      if (!spell.data.spellLists?.includes(`${cls.source}:${cls.name}`))
        tasks.push(
          `${spell.name}: lista da classe não confirmada; mestre deve revisar a origem desta escolha.`,
        );
  if (system && cls)
    system.preparation = {
      classId: cls.id,
      source: cls.source,
      edition: ruleset,
      tasks: tasks.map((text) => ({ text, done: false })),
    };
  const equipmentValid = !equipmentPlan.structured || equipmentResolution.complete;
  const canNext = [
    !!name.trim(),
    !!cls && validSkills,
    !!race && !!bg,
    validScores && validBonus,
    equipmentValid && spellIssues.length === 0,
    !!system,
  ][step];
  async function finish() {
    if (!system || !campaignId || busy) return;
    setBusy(true);
    setError("");
    try {
      const result = await api<{ actor: Actor }>(`/campaigns/${campaignId}/actors`, {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), type: "character", system }),
      });
      upsertActor(result.actor);
      setSelectedActorId(result.actor.id);
      localStorage.removeItem(draftKey);
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Não foi possível criar a ficha.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="character-wizard">
      <header>
        <h3>Criar personagem</h3>
        <button onClick={onClose} disabled={busy}>
          Voltar às fichas
        </button>
      </header>
      {draftNotice && <p role="status">{draftNotice}</p>}
      <p>Personagem de nível 1 · D&D {ruleset.slice(-4)}</p>
      <ol className="wizard-steps">
        {steps.map((label, index) => (
          <li key={label} aria-current={index === step ? "step" : undefined}>
            {label}
          </li>
        ))}
      </ol>

      {step === 0 && <IdentityStep name={name} onName={setName} />}
      {step === 1 && (
        <ClassStep
          ruleset={ruleset}
          selectedClass={cls}
          skillChoice={skillChoice}
          skills={skills}
          onClass={(entry) => {
            setClass(entry);
            setSkills([]);
            setEquipment([]);
            setEquipmentSelections({});
          }}
          onSkills={setSkills}
        />
      )}
      {step === 2 && (
        <OriginStep
          ruleset={ruleset}
          race={race}
          background={bg}
          onRace={setRace}
          onBackground={setBg}
        />
      )}
      {step === 3 && (
        <AbilitiesStep
          scoreMethod={scoreMethod}
          setScoreMethod={setScoreMethod}
          scores={scores}
          setScores={setScores}
          bonuses={bonuses}
          points={points}
          validScores={validScores}
          abilityOptions={abilityOptions}
          abilityOption={abilityOption}
          setAbilityOption={setAbilityOption}
          needsChoice={needsChoice}
          weights={weights}
          chosenAbilities={chosenAbilities}
          setChosenAbilities={setChosenAbilities}
          allowed={allowed}
          validBonus={validBonus}
          originName={origin?.name}
        />
      )}
      {step === 4 && (
        <EquipmentSpellsStep
          ruleset={ruleset}
          selectedClass={cls}
          limits={limits}
          spellIssues={spellIssues}
          equipmentPlan={equipmentPlan}
          equipmentResolution={equipmentResolution}
          equipmentSelections={equipmentSelections}
          setEquipmentSelections={setEquipmentSelections}
          itemChoice={itemChoice}
          setItemChoice={setItemChoice}
          equipment={equipment}
          setEquipment={setEquipment}
          spellChoice={spellChoice}
          setSpellChoice={setSpellChoice}
          spells={spells}
          setSpells={setSpells}
          prepared={prepared}
          setPrepared={setPrepared}
        />
      )}
      {step === 5 && system && (
        <ReviewStep
          name={name}
          selectedClass={cls}
          race={race}
          background={bg}
          system={system}
          tasks={tasks}
        />
      )}

      {error && (
        <p role="alert" className="notice notice--error">
          {error}
        </p>
      )}
      <footer>
        <button disabled={step === 0 || busy} onClick={() => setStep((value) => value - 1)}>
          Anterior
        </button>
        {step < 5 ? (
          <button
            className="primary"
            disabled={!canNext}
            onClick={() => setStep((value) => value + 1)}
          >
            Continuar
          </button>
        ) : (
          <button className="primary" disabled={busy || !canNext} onClick={() => void finish()}>
            {busy ? "Criando…" : "Criar ficha"}
          </button>
        )}
      </footer>
    </section>
  );
}
