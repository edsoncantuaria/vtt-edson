import { describe, expect, it } from "vitest";
import { ActorSchema, emptyActorSystem } from "@vtt/core";
import {
  availableSpellSlots,
  componentSummary,
  missileAllocations,
  missileCount,
  type SpellOption,
} from "./spellcasting";

const missile: SpellOption = {
  actionId: "spell:4",
  documentId: 4,
  name: "Magic Missile",
  source: "PHB",
  edition: "5e-2014",
  level: 1,
  kind: "missiles",
  prepared: true,
  requiresPreparation: true,
  ritual: false,
  ritualWithoutPreparation: false,
  components: { v: true, s: true, m: null },
  rangeFeet: 120,
  concentration: false,
  areaFeet: null,
  canCast: true,
  canRitual: false,
};

describe("escolhas de conjuração guiada", () => {
  it("mostra só espaços disponíveis iguais ou superiores ao nível da magia", () => {
    const system = emptyActorSystem();
    system.spells.slots = {
      "1": { max: 2, used: 2 },
      "2": { max: 1, used: 0 },
      "3": { max: 2, used: 1 },
    };
    const actor = ActorSchema.parse({
      id: 1,
      campaignId: 1,
      ownerUserId: 1,
      name: "Mago",
      type: "character",
      system,
      documents: [],
      activeEffects: [],
    });
    expect(availableSpellSlots(actor, missile)).toEqual([2, 3]);
    expect(availableSpellSlots(actor, { ...missile, level: 3 })).toEqual([3]);
    expect(missileCount(missile, 3)).toBe(5);
  });
  it("rejeita distribuição incompleta, duplicada ou sem um míssil por alvo", () => {
    expect(missileAllocations(["orc", "goblin"], { orc: 2, goblin: 1 }, 3)).toEqual([
      { tokenId: "orc", count: 2 },
      { tokenId: "goblin", count: 1 },
    ]);
    expect(missileAllocations(["orc", "goblin"], { orc: 3, goblin: 0 }, 3)).toBeNull();
    expect(missileAllocations(["orc"], { orc: 2 }, 3)).toBeNull();
    expect(missileAllocations(["orc", "orc"], { orc: 3 }, 3)).toEqual([
      { tokenId: "orc", count: 3 },
    ]);
    expect(componentSummary({ ...missile, components: { v: true, s: true, m: "bat guano" } })).toBe(
      "V, S, M (bat guano)",
    );
  });
});
