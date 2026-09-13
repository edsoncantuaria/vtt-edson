import type { ActorSystem } from "@vtt/core";
import { Icon } from "../Icon";
import { EditorSection, NumberField, type MutateActorSystem } from "./ActorEditorFields";

export function EquipmentSection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <>
      {system.inventory.map((item, index) => (
        <section className="editor-item" key={item.id}>
          <div className="editor-item__head">
            <label>
              Item
              <input
                required
                value={item.name}
                onChange={(event) =>
                  mutate((next) => {
                    next.inventory[index].name = event.target.value;
                  })
                }
              />
            </label>
            <button
              type="button"
              className="icon-button danger"
              aria-label={`Remover ${item.name}`}
              onClick={() =>
                mutate((next) => {
                  next.inventory.splice(index, 1);
                })
              }
            >
              <Icon name="trash" size={16} />
            </button>
          </div>
          <div className="editor-grid">
            <NumberField
              label="Quantidade"
              min={1}
              value={item.quantity}
              onChange={(value) =>
                mutate((next) => {
                  next.inventory[index].quantity = value;
                })
              }
            />
            <label>
              Bônus de ataque
              <input
                type="number"
                value={item.attackBonus ?? ""}
                onChange={(event) =>
                  mutate((next) => {
                    next.inventory[index].attackBonus =
                      event.target.value === "" ? undefined : Number(event.target.value);
                  })
                }
              />
            </label>
            <label>
              Dano
              <input
                value={item.damage ?? ""}
                placeholder="1d8+3"
                onChange={(event) =>
                  mutate((next) => {
                    next.inventory[index].damage = event.target.value || undefined;
                  })
                }
              />
            </label>
          </div>
          <label>
            Descrição
            <textarea
              rows={2}
              value={item.description ?? ""}
              onChange={(event) =>
                mutate((next) => {
                  next.inventory[index].description = event.target.value;
                })
              }
            />
          </label>
        </section>
      ))}
      <button
        type="button"
        onClick={() =>
          mutate((next) => {
            next.inventory.push({
              id: crypto.randomUUID(),
              name: "Novo item",
              quantity: 1,
              equipped: false,
            });
          })
        }
      >
        <Icon name="plus" size={16} />
        Adicionar item
      </button>
      <EditorSection title="Moedas">
        <div className="editor-grid">
          {(["cp", "sp", "ep", "gp", "pp"] as const).map((currency) => (
            <NumberField
              key={currency}
              label={
                { cp: "Cobre", sp: "Prata", ep: "Electro", gp: "Ouro", pp: "Platina" }[currency]
              }
              max={999999}
              value={system.currency[currency]}
              onChange={(value) =>
                mutate((next) => {
                  next.currency[currency] = value;
                })
              }
            />
          ))}
        </div>
      </EditorSection>
    </>
  );
}

export function SpellsSection({
  system,
  mutate,
}: {
  system: ActorSystem;
  mutate: MutateActorSystem;
}) {
  return (
    <>
      <EditorSection title="Espaços por círculo">
        <div className="editor-grid">
          {Array.from({ length: 9 }, (_, index) => String(index + 1)).map((level) => (
            <NumberField
              key={level}
              label={`${level}º círculo · total`}
              max={20}
              value={system.spells.slots[level]?.max ?? 0}
              onChange={(max) =>
                mutate((next) => {
                  next.spells.slots[level] = {
                    max,
                    used: Math.min(max, next.spells.slots[level]?.used ?? 0),
                  };
                })
              }
            />
          ))}
        </div>
      </EditorSection>
      {system.spells.known.map((spell, index) => (
        <section className="editor-item" key={spell.id}>
          <div className="editor-item__head">
            <label>
              Magia
              <input
                required
                value={spell.name}
                onChange={(event) =>
                  mutate((next) => {
                    next.spells.known[index].name = event.target.value;
                  })
                }
              />
            </label>
            <NumberField
              label="Círculo (0 = truque)"
              max={9}
              value={spell.level}
              onChange={(value) =>
                mutate((next) => {
                  next.spells.known[index].level = value;
                })
              }
            />
            <button
              type="button"
              className="icon-button danger"
              aria-label={`Remover ${spell.name}`}
              onClick={() =>
                mutate((next) => {
                  next.spells.known.splice(index, 1);
                })
              }
            >
              <Icon name="trash" size={16} />
            </button>
          </div>
          <label>
            Descrição
            <textarea
              rows={3}
              value={spell.description ?? ""}
              onChange={(event) =>
                mutate((next) => {
                  next.spells.known[index].description = event.target.value;
                })
              }
            />
          </label>
        </section>
      ))}
      <button
        type="button"
        onClick={() =>
          mutate((next) => {
            next.spells.known.push({
              id: crypto.randomUUID(),
              name: "Nova magia",
              level: 1,
              prepared: false,
            });
          })
        }
      >
        <Icon name="plus" size={16} />
        Adicionar magia
      </button>
    </>
  );
}
