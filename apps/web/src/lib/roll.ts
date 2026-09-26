import { RollRecordSchema, type RollRecord } from "@vtt/core";
import { api } from "./api";
import { updateScene } from "./scene";

export type RollOptions = {
  requestId?: string;
  actorId?: number;
  context?: RollRecord["context"];
  mode?: RollRecord["mode"];
  visibility?: "public" | "gm";
  modifier?: number;
  extraDice?: string;
};

export function rollToChat(
  sceneId: number,
  formula: string,
  label?: string,
  options: RollOptions = {},
) {
  return updateScene(sceneId, "/rolls", {
    requestId: options.requestId ?? crypto.randomUUID(),
    formula,
    label,
    actorId: options.actorId,
    context: options.context ?? "custom",
    mode: options.mode ?? "normal",
    visibility: options.visibility ?? "public",
    ...(options.modifier !== undefined ? { modifier: options.modifier } : {}),
    ...(options.extraDice ? { extraDice: options.extraDice } : {}),
  });
}

export async function rollHistory(sceneId: number): Promise<RollRecord[]> {
  return (await rollHistoryPage(sceneId)).rolls;
}

export async function rollHistoryPage(
  sceneId: number,
  page = 1,
): Promise<{ rolls: RollRecord[]; page: number; hasMore: boolean }> {
  const result = await api<{ rolls: RollRecord[]; page?: number; hasMore?: boolean }>(
    `/scenes/${sceneId}/rolls${page > 1 ? `?page=${page}` : ""}`,
  );
  return {
    rolls: result.rolls.map((record) => RollRecordSchema.parse(record)),
    page: result.page ?? page,
    hasMore: result.hasMore ?? false,
  };
}
