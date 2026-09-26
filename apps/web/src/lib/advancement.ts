import type { Ability, Actor, ActorSystem } from "@vtt/core";
import { api } from "./api";

/** The preview and confirmation must share the exact request ID and revision. */
export type ActorAdvancementRequest = {
  requestId: string;
  revision: number;
  classId: number;
  targetLevel: number;
  subclassId?: number;
  featId?: number;
  asi?: Partial<Record<Ability, number>>;
  overrideReason?: string;
  system: ActorSystem;
};

export type ActorAdvancementPreview = {
  preview: {
    level: [number, number];
    hp: [number, number];
    proficiency: [number, number];
    slots: [ActorSystem["spells"]["slots"], ActorSystem["spells"]["slots"]];
  };
  revision: number;
};

export type ActorAdvancementConfirmation = {
  actor: Actor;
  alreadyApplied: boolean;
  advancementId: number;
};

const path = (actorId: number) => `/actors/${actorId}/advancements`;

export function previewActorAdvancement(actorId: number, request: ActorAdvancementRequest) {
  return api<ActorAdvancementPreview>(path(actorId), {
    method: "POST",
    body: JSON.stringify({ ...request, preview: true }),
  });
}

export function confirmActorAdvancement(actorId: number, request: ActorAdvancementRequest) {
  return api<ActorAdvancementConfirmation>(path(actorId), {
    method: "POST",
    body: JSON.stringify(request),
  });
}
