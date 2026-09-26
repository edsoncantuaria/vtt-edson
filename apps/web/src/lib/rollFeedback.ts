import type { ChatMessage } from "@vtt/core";

export type RollFeedbackPreferences = { animate: boolean; sound: boolean };

const STORAGE_KEY = "vtt.roll-feedback.v1";
export const DEFAULT_ROLL_FEEDBACK: RollFeedbackPreferences = { animate: true, sound: false };

export function readRollFeedbackPreferences(): RollFeedbackPreferences {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "null");
    return {
      animate: typeof stored?.animate === "boolean" ? stored.animate : true,
      sound: stored?.sound === true,
    };
  } catch {
    return { ...DEFAULT_ROLL_FEEDBACK };
  }
}

export function saveRollFeedbackPreferences(next: RollFeedbackPreferences): void {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  window.dispatchEvent(new Event("vtt:roll-feedback-preferences"));
}

/** Mark every observed ID, including text, so reconnects never replay existing chat. */
export function newlyArrivedRolls(messages: ChatMessage[], seen: Set<string>): ChatMessage[] {
  const fresh: ChatMessage[] = [];
  for (const message of messages) {
    if (seen.has(message.id)) continue;
    seen.add(message.id);
    if (
      (message.type === "roll" || message.type === "action") &&
      typeof message.total === "number"
    ) {
      fresh.push(message);
    }
  }
  return fresh;
}
