import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import type { ChatMessage } from "@vtt/core";
import { RollFeedbackCard } from "../components/RollFeedback";
import {
  DEFAULT_ROLL_FEEDBACK,
  newlyArrivedRolls,
  readRollFeedbackPreferences,
  saveRollFeedbackPreferences,
} from "./rollFeedback";

const uuid = "43fa9151-0e32-43e5-90ef-cbc4c5fe0c10";
const roll: ChatMessage = {
  id: uuid,
  rollId: uuid,
  type: "roll",
  userId: 2,
  userName: "Hero",
  createdAt: "2026-09-26T13:00:00Z",
  label: "Hero · Ataque",
  formula: "2d20kh1+4",
  detail: "19 + 4 = 23 (descartado: 3)",
  total: 23,
  critical: false,
  fumble: false,
};

describe("brief roll feedback", () => {
  let values: Map<string, string>;
  const events = new EventTarget();

  beforeEach(() => {
    values = new Map();
    vi.stubGlobal("localStorage", {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => values.set(key, value),
    });
    vi.stubGlobal("window", events);
  });

  afterEach(() => vi.unstubAllGlobals());

  it("defaults to silent feedback, persists preferences and recovers corrupted storage", () => {
    expect(readRollFeedbackPreferences()).toEqual(DEFAULT_ROLL_FEEDBACK);
    const handler = vi.fn();
    window.addEventListener("vtt:roll-feedback-preferences", handler);
    saveRollFeedbackPreferences({ animate: false, sound: true });
    expect(readRollFeedbackPreferences()).toEqual({ animate: false, sound: true });
    expect(handler).toHaveBeenCalledTimes(1);
    values.set("vtt.roll-feedback.v1", "invalid-json");
    expect(readRollFeedbackPreferences()).toEqual(DEFAULT_ROLL_FEEDBACK);
    window.removeEventListener("vtt:roll-feedback-preferences", handler);
  });

  it("only announces new results, never existing history or repeated websocket snapshots", () => {
    const old = { ...roll, id: "old" };
    const text: ChatMessage = { ...roll, id: "text", type: "text", text: "Olá", total: undefined };
    const seen = new Set([old.id]); // initial scene snapshot was already rendered
    expect(newlyArrivedRolls([old, text, roll], seen)).toEqual([roll]);
    expect(newlyArrivedRolls([old, text, roll], seen)).toEqual([]);
    expect(seen.has(text.id)).toBe(true);
  });

  it("shows the authoritative formula, detail and total without a fake rolling number", () => {
    const html = renderToStaticMarkup(
      <RollFeedbackCard message={roll} animate={false} onDismiss={() => {}} />,
    );
    expect(html).toContain("Hero · Ataque");
    expect(html).toContain("2d20kh1+4");
    expect(html).toContain("descartado: 3");
    expect(html).toContain("23</strong>");
    expect(html).not.toContain("stage-roll-feedback--animated");
    const critical = renderToStaticMarkup(
      <RollFeedbackCard message={{ ...roll, critical: true }} animate onDismiss={() => {}} />,
    );
    expect(critical).toContain("20 natural · crítico");
    expect(critical).toContain("stage-roll-feedback--animated");
    const fumble = renderToStaticMarkup(
      <RollFeedbackCard
        message={{ ...roll, critical: false, fumble: true }}
        animate
        onDismiss={() => {}}
      />,
    );
    expect(fumble).toContain("1 natural · falha crítica");
  });
});
