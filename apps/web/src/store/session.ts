import { create } from "zustand";
import type { Actor, Combat, Role, SceneState } from "@vtt/core";
export { isManagerRole } from "@vtt/core";
import { emptySceneState, SceneStateSchema, ActorSchema } from "@vtt/core";
import { publicAssetUrl } from "../lib/assets";
export type Tool =
  | "pan"
  | "select"
  | "token"
  | "wall"
  | "door"
  | "light"
  | "fog"
  | "ruler"
  | "circle"
  | "cone"
  | "line"
  | "radius"
  | "draw"
  | "label"
  | "ping"
  | "region";
export type Panel = "chat" | "actors" | "compendium" | "combat" | "scene" | "journal" | "tools";
export type Ruleset = "5e-2014" | "5e-2024";
export type User = { id: number; name: string; email: string };
export type RoomResult = {
  campaign: { id: number; name: string; ruleset: Ruleset };
  room: { code: string };
  scene: {
    id: number;
    name: string;
    role: Role;
    canEdit?: boolean;
    state: SceneState;
    backgroundUrl: string | null;
  };
};
export type RoomSummary = {
  code: string;
  campaignId: number;
  name: string;
  ruleset: Ruleset;
  gmName: string;
  sceneId: number;
  sceneName: string;
  role: Role;
  memberCount: number;
  backgroundUrl: string | null;
  updatedAt: string;
};
export type SceneSummary = RoomResult["scene"];
const sceneDefaults = () => ({
  roomCode: null as string | null,
  campaignId: null as number | null,
  campaignName: "",
  ruleset: "5e-2014" as Ruleset,
  sceneId: null as number | null,
  sceneName: "",
  role: null as Role | null,
  canEditScene: false,
  state: emptySceneState(),
  backgroundUrl: null as string | null,
  tool: "select" as Tool,
  panel: "chat" as Panel,
  actors: [] as Actor[],
  selectedActorId: null as number | null,
  targetActorIds: [] as number[],
  combat: null as Combat | null,
  error: null as string | null,
});
type Session = ReturnType<typeof sceneDefaults> & {
  user: User | null;
  token: string | null;
  setAuth: (user: User, token: string) => void;
  clearAuth: () => void;
  leaveScene: () => void;
  enterRoom: (room: RoomResult) => void;
  enterScene: (scene: SceneSummary) => void;
  patchState: (state: SceneState, backgroundUrl?: string | null) => void;
  setTool: (tool: Tool) => void;
  setPanel: (panel: Panel) => void;
  setActors: (actors: Actor[]) => void;
  upsertActor: (actor: Actor) => void;
  removeActor: (id: number) => void;
  setSelectedActorId: (id: number | null) => void;
  setTargetActorIds: (ids: number[]) => void;
  setCombat: (combat: Combat | null) => void;
  setError: (error: string | null) => void;
};
export const useSession = create<Session>((set) => ({
  ...sceneDefaults(),
  user: null,
  token: null,
  setAuth: (user, token) => set({ user, token }),
  clearAuth: () => set({ ...sceneDefaults(), user: null, token: null }),
  leaveScene: () => set(sceneDefaults()),
  enterRoom: ({ campaign, scene, room }) =>
    set({
      ...sceneDefaults(),
      campaignId: campaign.id,
      campaignName: campaign.name,
      ruleset: campaign.ruleset ?? "5e-2014",
      roomCode: room.code,
      sceneId: scene.id,
      sceneName: scene.name,
      role: scene.role,
      canEditScene: scene.canEdit ?? (scene.role === "gm" || scene.role === "assistant"),
      state: SceneStateSchema.parse(scene.state),
      backgroundUrl: publicAssetUrl(scene.backgroundUrl),
      panel: scene.role === "player" ? "actors" : "chat",
    }),
  enterScene: (scene) =>
    set((current) => ({
      sceneId: scene.id,
      sceneName: scene.name,
      role: scene.role,
      canEditScene: scene.canEdit ?? (scene.role === "gm" || scene.role === "assistant"),
      state: SceneStateSchema.parse(scene.state),
      backgroundUrl: publicAssetUrl(scene.backgroundUrl),
      tool: "select",
      combat: null,
      selectedActorId: null,
      targetActorIds: [],
      panel: scene.role === "player" ? "actors" : current.panel,
    })),
  patchState: (state, backgroundUrl) =>
    set((s) => ({
      state: SceneStateSchema.parse(state),
      backgroundUrl: publicAssetUrl(
        backgroundUrl !== undefined ? backgroundUrl : (state.backgroundUrl ?? s.backgroundUrl),
      ),
    })),
  setTool: (tool) => set({ tool }),
  setPanel: (panel) => set({ panel }),
  setActors: (actors) => set({ actors: actors.map((actor) => ActorSchema.parse(actor)) }),
  upsertActor: (input) => {
    const actor = ActorSchema.parse(input);
    return set((s) => ({
      actors: s.actors.some((a) => a.id === actor.id)
        ? s.actors.map((a) => (a.id === actor.id ? actor : a))
        : [...s.actors, actor],
    }));
  },
  removeActor: (id) =>
    set((s) => ({
      actors: s.actors.filter((a) => a.id !== id),
      selectedActorId: s.selectedActorId === id ? null : s.selectedActorId,
    })),
  setSelectedActorId: (selectedActorId) => set({ selectedActorId }),
  setTargetActorIds: (ids) => set({ targetActorIds: [...new Set(ids)] }),
  setCombat: (combat) => set({ combat }),
  setError: (error) => set({ error }),
}));
