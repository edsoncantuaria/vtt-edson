import type { Ability } from "@vtt/core";

export type AbilityChoice = {
  from?: Ability[];
  count?: number;
  amount?: number;
  weighted?: {
    from?: Ability[];
    weights?: number[];
  };
};

export type AbilityOption = Partial<Record<Ability, number>> & {
  choose?: AbilityChoice;
};

export type SkillChoice = {
  count: number;
  from: string[];
};
