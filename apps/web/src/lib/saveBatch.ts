import type { Actor } from "@vtt/core";

export type SaveBatchRoll = {
  id?: string;
  total: number;
  formula: string;
  detail: string;
};

export type SaveBatchDecision = {
  success: boolean;
  manual?: boolean;
  reason?: string;
  userId?: number;
  userName?: string;
  createdAt?: string;
  mode?: string;
  roll?: SaveBatchRoll;
  history?: SaveBatchDecision[];
};

export type SaveBatchRow = {
  actorId: Actor["id"];
  name: string;
  type: Actor["type"];
  ownerUserId: number | null;
  save: SaveBatchDecision | null;
  preview: { damage: number; pendingSave: boolean; steps: string[] };
  application: {
    undone: boolean;
    resolution: { damage: number; reason?: string; userId?: number };
  } | null;
};

export type SaveBatchStatus = {
  save: {
    ability: "str" | "dex" | "con" | "int" | "wis" | "cha";
    dc: number;
    effect: "half" | "none";
  };
  rows: SaveBatchRow[];
  actionUndone: boolean;
  operations: Array<{
    requestId: string;
    kind: string;
    actorIds: number[];
    userId: number;
    userName: string;
    createdAt: string;
  }>;
};

export function pendingNpcIds(rows: SaveBatchRow[]): number[] {
  return rows
    .filter(
      (row) =>
        (row.type !== "character" || row.ownerUserId === null) && !row.save && !row.application,
    )
    .map((row) => row.actorId);
}

export function batchReadyIds(rows: SaveBatchRow[]): number[] {
  return rows
    .filter((row) => row.save && !row.preview.pendingSave && !row.application)
    .map((row) => row.actorId);
}

export function unresolvedSaveNames(rows: SaveBatchRow[]): string[] {
  return rows.filter((row) => !row.save && !row.application).map((row) => row.name);
}
