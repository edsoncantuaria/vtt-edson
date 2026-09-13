import { ABILITY_LABELS, type Ability, type ActorSystem } from "@vtt/core";
import type { Dispatch, SetStateAction } from "react";
import type { CatalogEntry } from "../../lib/catalog";
import { STANDARD_SCORES } from "../../lib/characterBuilder";
import type { spellLimits } from "../../lib/characterPreparation";
import type { StartingEquipmentPlan, resolveStartingEquipment } from "../../lib/startingEquipment";
import { CatalogPicker } from "./CatalogPicker";
import type { AbilityOption, SkillChoice } from "./types";

const ABILITIES = Object.keys(ABILITY_LABELS) as Ability[];

export function IdentityStep({ name, onName }: { name: string; onName: (name: string) => void }) {
  return (
    <label>
      Nome do personagem
      <input
        autoFocus
        maxLength={120}
        value={name}
        onChange={(event) => onName(event.target.value)}
        placeholder="Como seu herói se chama?"
      />
    </label>
  );
}

export function ClassStep({
  ruleset,
  selectedClass,
  skillChoice,
  skills,
  onClass,
  onSkills,
}: {
  ruleset: string;
  selectedClass: CatalogEntry | null;
  skillChoice?: SkillChoice;
  skills: string[];
  onClass: (entry: CatalogEntry) => void;
  onSkills: Dispatch<SetStateAction<string[]>>;
}) {
  return (
    <>
      <h4>Escolha a classe</h4>
      <CatalogPicker kind="classes" edition={ruleset} value={selectedClass} onChange={onClass} />
      {skillChoice && (
        <fieldset>
          <legend>Escolha {skillChoice.count} perícias</legend>
          {skillChoice.from.map((skill) => (
            <label className="check-label" key={skill}>
              <input
                type="checkbox"
                checked={skills.includes(skill)}
                onChange={(event) =>
                  onSkills((current) =>
                    event.target.checked
                      ? [...current, skill]
                      : current.filter((item) => item !== skill),
                  )
                }
              />
              {skill}
            </label>
          ))}
        </fieldset>
      )}
    </>
  );
}

export function OriginStep({
  ruleset,
  race,
  background,
  onRace,
  onBackground,
}: {
  ruleset: string;
  race: CatalogEntry | null;
  background: CatalogEntry | null;
  onRace: (entry: CatalogEntry) => void;
  onBackground: (entry: CatalogEntry) => void;
}) {
  return (
    <>
      <h4>{ruleset === "5e-2024" ? "Espécie" : "Raça"}</h4>
      <CatalogPicker kind="races" edition={ruleset} value={race} onChange={onRace} />
      <h4>Antecedente</h4>
      <CatalogPicker
        kind="backgrounds"
        edition={ruleset}
        value={background}
        onChange={onBackground}
      />
    </>
  );
}

export function AbilitiesStep({
  scoreMethod,
  setScoreMethod,
  scores,
  setScores,
  bonuses,
  points,
  validScores,
  abilityOptions,
  abilityOption,
  setAbilityOption,
  needsChoice,
  weights,
  chosenAbilities,
  setChosenAbilities,
  allowed,
  validBonus,
  originName,
}: {
  scoreMethod: "standard" | "points";
  setScoreMethod: (method: "standard" | "points") => void;
  scores: Record<Ability, number>;
  setScores: Dispatch<SetStateAction<Record<Ability, number>>>;
  bonuses: Partial<Record<Ability, number>>;
  points: number;
  validScores: boolean;
  abilityOptions: AbilityOption[];
  abilityOption: number;
  setAbilityOption: (index: number) => void;
  needsChoice: boolean;
  weights: number[];
  chosenAbilities: Ability[];
  setChosenAbilities: Dispatch<SetStateAction<Ability[]>>;
  allowed: Ability[];
  validBonus: boolean;
  originName?: string;
}) {
  function chooseScoreMethod(method: "standard" | "points") {
    setScoreMethod(method);
    setScores(
      method === "points"
        ? { str: 8, dex: 8, con: 8, int: 8, wis: 8, cha: 8 }
        : { str: 15, dex: 14, con: 13, int: 12, wis: 10, cha: 8 },
    );
  }

  return (
    <>
      <h4>Distribua os atributos</h4>
      <label>
        Método
        <select
          value={scoreMethod}
          onChange={(event) => chooseScoreMethod(event.target.value as "standard" | "points")}
        >
          <option value="standard">Valores padrão</option>
          <option value="points">Compra de pontos · 27 pontos</option>
        </select>
      </label>
      <p>
        {scoreMethod === "points"
          ? `${points} de 27 pontos usados. Valores 14 e 15 custam 7 e 9 pontos.`
          : "Use cada valor uma vez: 15, 14, 13, 12, 10 e 8."}
      </p>
      <div className="editor-grid">
        {ABILITIES.map((ability) => (
          <label key={ability}>
            {ABILITY_LABELS[ability]}
            <select
              value={scores[ability]}
              onChange={(event) =>
                setScores((current) => ({
                  ...current,
                  [ability]: Number(event.target.value),
                }))
              }
            >
              {(scoreMethod === "points" ? [8, 9, 10, 11, 12, 13, 14, 15] : STANDARD_SCORES).map(
                (score) => (
                  <option key={score}>{score}</option>
                ),
              )}
            </select>
            <small>Bônus de origem: +{bonuses[ability] ?? 0}</small>
          </label>
        ))}
      </div>
      {!validScores && (
        <p role="status">
          {scoreMethod === "points"
            ? "A distribuição excede 27 pontos."
            : "Distribua cada valor padrão apenas uma vez."}
        </p>
      )}
      {abilityOptions.length > 1 && (
        <label>
          Opção de bônus da origem
          <select
            value={abilityOption}
            onChange={(event) => setAbilityOption(Number(event.target.value))}
          >
            {abilityOptions.map((option, index) => (
              <option key={index} value={index}>
                {option.choose?.weighted?.weights?.map((weight) => `+${weight}`).join(" / ") ??
                  (option.choose
                    ? `${option.choose.count ?? 1} atributo(s) com +${option.choose.amount ?? 1}`
                    : "Bônus fixos da origem")}
              </option>
            ))}
          </select>
        </label>
      )}
      {needsChoice && (
        <>
          <h4>Bônus de {originName}</h4>
          <div className="editor-grid">
            {weights.map((weight, index) => (
              <label key={index}>
                +{weight} em
                <select
                  value={chosenAbilities[index] ?? ""}
                  onChange={(event) =>
                    setChosenAbilities((current) =>
                      current.map((ability, abilityIndex) =>
                        abilityIndex === index ? (event.target.value as Ability) : ability,
                      ),
                    )
                  }
                >
                  {allowed.map((ability) => (
                    <option key={ability} value={ability}>
                      {ABILITY_LABELS[ability]}
                    </option>
                  ))}
                </select>
              </label>
            ))}
          </div>
          {!validBonus && (
            <p role="status">Escolha atributos diferentes entre as opções permitidas.</p>
          )}
        </>
      )}
    </>
  );
}

type EquipmentResolution = ReturnType<typeof resolveStartingEquipment>;
type SpellLimits = ReturnType<typeof spellLimits>;

export function EquipmentSpellsStep({
  ruleset,
  selectedClass,
  limits,
  spellIssues,
  equipmentPlan,
  equipmentResolution,
  equipmentSelections,
  setEquipmentSelections,
  itemChoice,
  setItemChoice,
  equipment,
  setEquipment,
  spellChoice,
  setSpellChoice,
  spells,
  setSpells,
  prepared,
  setPrepared,
}: {
  ruleset: string;
  selectedClass: CatalogEntry | null;
  limits: SpellLimits | null;
  spellIssues: string[];
  equipmentPlan: StartingEquipmentPlan;
  equipmentResolution: EquipmentResolution;
  equipmentSelections: Record<string, string>;
  setEquipmentSelections: Dispatch<SetStateAction<Record<string, string>>>;
  itemChoice: CatalogEntry | null;
  setItemChoice: (entry: CatalogEntry) => void;
  equipment: CatalogEntry[];
  setEquipment: Dispatch<SetStateAction<CatalogEntry[]>>;
  spellChoice: CatalogEntry | null;
  setSpellChoice: (entry: CatalogEntry) => void;
  spells: CatalogEntry[];
  setSpells: Dispatch<SetStateAction<CatalogEntry[]>>;
  prepared: string[];
  setPrepared: Dispatch<SetStateAction<string[]>>;
}) {
  return (
    <>
      <p>
        As quantidades conhecidas da progressão são validadas antes de continuar. Escolhas de
        equipamento, talentos e exceções condicionais permanecem registradas para revisão.
      </p>
      {limits && (
        <p>
          Truques: {limits.cantrips} · Conhecidas/grimório: {limits.known ?? "lista da classe"} ·
          Preparadas: {limits.prepared ?? "não se aplica"} · Até nível {limits.maxLevel}
        </p>
      )}
      {spellIssues.map((issue) => (
        <p role="status" className="notice notice--error" key={issue}>
          {issue}
        </p>
      ))}

      <h4>Equipamento</h4>
      {equipmentPlan.structured ? (
        <>
          <p>
            Escolha entre as opções de equipamento inicial disponíveis para classe/antecedente.
            Valores monetários do catálogo 2024 são tratados em cobre (100 = 1 PO).
          </p>
          {equipmentPlan.groups.map((group, index) => (
            <fieldset key={group.id}>
              <legend>Grupo {index + 1}</legend>
              {group.options.map((option) => (
                <label className="check-label" key={option.key}>
                  <input
                    type="radio"
                    name={`equipment-${group.id}`}
                    checked={
                      (equipmentSelections[group.id] ??
                        (group.options.length === 1 ? group.options[0].key : "")) === option.key
                    }
                    onChange={() =>
                      setEquipmentSelections((current) => ({
                        ...current,
                        [group.id]: option.key,
                      }))
                    }
                  />
                  {option.label}
                </label>
              ))}
            </fieldset>
          ))}
          {equipmentResolution.manualTypes.length > 0 && (
            <ManualEquipmentPicker
              ruleset={ruleset}
              itemChoice={itemChoice}
              setItemChoice={setItemChoice}
              equipment={equipment}
              setEquipment={setEquipment}
              maxItems={equipmentResolution.manualTypes.length}
              description={`As opções genéricas abaixo precisam ser escolhidas manualmente no catálogo: ${equipmentResolution.manualTypes.join(", ")}.`}
            />
          )}
          {equipmentResolution.issues.map((issue) => (
            <p role="status" className="notice notice--error" key={issue}>
              {issue}
            </p>
          ))}
          {(equipmentResolution.currencyGp > 0 || equipmentResolution.currencyCp > 0) && (
            <p>
              Moedas iniciais selecionadas: {equipmentResolution.currencyGp} PO
              {equipmentResolution.currencyCp > 0 ? ` + ${equipmentResolution.currencyCp} PC` : ""}.
            </p>
          )}
          <ul>
            {equipmentResolution.inventory.map((item) => (
              <li key={item.id}>
                {item.quantity}× {item.name}
              </li>
            ))}
          </ul>
        </>
      ) : (
        <ManualEquipmentPicker
          ruleset={ruleset}
          itemChoice={itemChoice}
          setItemChoice={setItemChoice}
          equipment={equipment}
          setEquipment={setEquipment}
          description="O catálogo desta classe/antecedente não possui equipamento inicial estruturado. Registre as escolhas manualmente."
        />
      )}

      <h4>Magias e truques</h4>
      <CatalogPicker
        kind="spells"
        classId={selectedClass?.id}
        maxLevel={limits?.maxLevel}
        edition={ruleset}
        value={spellChoice}
        onChange={setSpellChoice}
      />
      <button
        disabled={!spellChoice || spells.some((spell) => spell.slug === spellChoice.slug)}
        onClick={() => spellChoice && setSpells((items) => [...items, spellChoice])}
      >
        Adicionar magia
      </button>
      {spells.map((spell) => (
        <p key={spell.slug}>
          {spell.name} · {spell.level === 0 ? "truque" : `nível ${spell.level}`}{" "}
          {spell.level !== 0 && (
            <label className="check-label">
              <input
                type="checkbox"
                checked={prepared.includes(spell.slug)}
                onChange={(event) =>
                  setPrepared((current) =>
                    event.target.checked
                      ? [...current, spell.slug]
                      : current.filter((slug) => slug !== spell.slug),
                  )
                }
              />
              Preparada
            </label>
          )}{" "}
          <button
            onClick={() => setSpells((items) => items.filter((item) => item.slug !== spell.slug))}
          >
            Remover
          </button>
        </p>
      ))}
    </>
  );
}

function ManualEquipmentPicker({
  ruleset,
  itemChoice,
  setItemChoice,
  equipment,
  setEquipment,
  maxItems,
  description,
}: {
  ruleset: string;
  itemChoice: CatalogEntry | null;
  setItemChoice: (entry: CatalogEntry) => void;
  equipment: CatalogEntry[];
  setEquipment: Dispatch<SetStateAction<CatalogEntry[]>>;
  maxItems?: number;
  description: string;
}) {
  const duplicate = itemChoice ? equipment.some((item) => item.slug === itemChoice.slug) : false;
  const full = maxItems !== undefined && equipment.length >= maxItems;

  return (
    <>
      <p>{description}</p>
      <CatalogPicker kind="items" edition={ruleset} value={itemChoice} onChange={setItemChoice} />
      <button
        disabled={!itemChoice || duplicate || full}
        onClick={() => itemChoice && setEquipment((items) => [...items, itemChoice])}
      >
        {maxItems === undefined ? "Adicionar equipamento" : "Usar nesta escolha"}
      </button>
      {equipment.map((item) => (
        <p key={item.slug}>
          {item.name}{" "}
          <button
            onClick={() =>
              setEquipment((items) => items.filter((current) => current.slug !== item.slug))
            }
          >
            Remover
          </button>
        </p>
      ))}
    </>
  );
}

export function ReviewStep({
  name,
  selectedClass,
  race,
  background,
  system,
  tasks,
}: {
  name: string;
  selectedClass: CatalogEntry | null;
  race: CatalogEntry | null;
  background: CatalogEntry | null;
  system: ActorSystem;
  tasks: string[];
}) {
  return (
    <>
      <h4>
        {name} · {selectedClass?.name}
      </h4>
      <p>
        {race?.name} · {background?.name}
      </p>
      <dl className="wizard-review">
        <dt>PV iniciais</dt>
        <dd>{system.hp.max}</dd>
        <dt>CA sem armadura</dt>
        <dd>{system.ac}</dd>
        <dt>Deslocamento</dt>
        <dd>{system.speed} ft</dd>
        <dt>Características importadas</dt>
        <dd>{system.features.length}</dd>
      </dl>
      <h4>Pendências para revisar na mesa</h4>
      <ul>
        {tasks.map((task) => (
          <li key={task}>{task}</li>
        ))}
      </ul>
      <p>
        Equipamento e magias selecionados serão incluídos na ficha. Revise talentos e escolhas
        específicas. O assistente prepara atributos, PV, salvaguardas, perícias e características;
        escolhas específicas dos livros continuam editáveis.
      </p>
    </>
  );
}
