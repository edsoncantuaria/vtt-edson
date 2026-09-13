import type { ActorSystem } from "@vtt/core";
import { EditorSection, NumberField, type MutateActorSystem } from "./ActorEditorFields";

export function CurrencySection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <EditorSection title="Moedas">
      <div className="editor-grid">
        {(["cp", "sp", "ep", "gp", "pp"] as const).map((currency) => (
          <NumberField
            key={currency}
            label={{ cp: "Cobre", sp: "Prata", ep: "Electro", gp: "Ouro", pp: "Platina" }[currency]}
            max={999999}
            value={system.currency[currency]}
            onChange={(next) =>
              mutate((draft) => {
                draft.currency[currency] = next;
              })
            }
          />
        ))}
      </div>
    </EditorSection>
  );
}

export function SpellSlotsSection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <EditorSection title="Espaços por círculo">
      <div className="editor-grid">
        {Array.from({ length: 9 }, (_, index) => String(index + 1)).map((level) => (
          <NumberField
            key={level}
            label={`${level}º círculo · total`}
            max={20}
            value={system.spells.slots[level]?.max ?? 0}
            onChange={(max) =>
              mutate((draft) => {
                draft.spells.slots[level] = {
                  max,
                  used: Math.min(max, draft.spells.slots[level]?.used ?? 0),
                };
              })
            }
          />
        ))}
      </div>
    </EditorSection>
  );
}
