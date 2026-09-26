import { useEffect, useRef, useState } from "react";
import type { ChatMessage } from "@vtt/core";
import { newlyArrivedRolls, readRollFeedbackPreferences } from "../lib/rollFeedback";

export type LocalRollFeedback = { sceneId: number; message: ChatMessage };

export function RollFeedbackCard({
  message,
  animate,
  onDismiss,
}: {
  message: ChatMessage;
  animate: boolean;
  onDismiss: () => void;
}) {
  return (
    <aside
      className={`stage-roll-feedback${animate ? " stage-roll-feedback--animated" : ""}`}
      role="status"
      aria-label="Resultado recente da rolagem"
    >
      <div>
        <span>{message.label || (message.type === "action" ? "Ação" : "Rolagem")}</span>
        <code>{message.formula || "Resultado da ação"}</code>
        <small>{message.detail || message.rolls?.[0]?.detail}</small>
        {message.rolls?.slice(1).map((roll) => (
          <small key={roll.id ?? `${roll.kind}:${roll.formula}`}>
            Dano: {roll.detail} · {roll.total}
          </small>
        ))}
        {message.critical && <b className="roll-tag">20 natural · crítico</b>}
        {message.fumble && <b className="roll-tag">1 natural · falha crítica</b>}
      </div>
      <strong>{message.total}</strong>
      <button
        type="button"
        className="icon-button"
        onClick={onDismiss}
        aria-label="Dispensar resultado"
      >
        ×
      </button>
    </aside>
  );
}

function soundForRoll(critical: boolean, fumble: boolean) {
  try {
    const audio = new AudioContext();
    const tone = audio.createOscillator();
    const gain = audio.createGain();
    tone.type = "sine";
    tone.frequency.value = critical ? 740 : fumble ? 250 : 460;
    gain.gain.setValueAtTime(0.035, audio.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.11);
    tone.connect(gain).connect(audio.destination);
    tone.start();
    tone.stop(audio.currentTime + 0.11);
    tone.onended = () => void audio.close();
  } catch {
    // Audio can be blocked by the browser; the persisted visual result remains available.
  }
}

export function RollFeedback({ sceneId, chat }: { sceneId: number; chat: ChatMessage[] }) {
  const seen = useRef(new Set(chat.map((message) => message.id)));
  const recentlyShown = useRef(new Set<string>());
  const [preferences, setPreferences] = useState(readRollFeedbackPreferences);
  const [latest, setLatest] = useState<ChatMessage | null>(null);

  useEffect(() => {
    const update = () => setPreferences(readRollFeedbackPreferences());
    window.addEventListener("vtt:roll-feedback-preferences", update);
    return () => window.removeEventListener("vtt:roll-feedback-preferences", update);
  }, []);

  useEffect(() => {
    const fresh = newlyArrivedRolls(chat, seen.current);
    const message = fresh.at(-1);
    if (!message || recentlyShown.current.has(message.id)) return;
    recentlyShown.current.add(message.id);
    setLatest(message);
    if (readRollFeedbackPreferences().sound) soundForRoll(!!message.critical, !!message.fumble);
  }, [chat]);

  useEffect(() => {
    const receive = (event: Event) => {
      const { sceneId: source, message } = (event as CustomEvent<LocalRollFeedback>).detail;
      if (source !== sceneId || recentlyShown.current.has(message.id)) return;
      recentlyShown.current.add(message.id);
      seen.current.add(message.id);
      setLatest(message);
      if (readRollFeedbackPreferences().sound) soundForRoll(!!message.critical, !!message.fumble);
    };
    window.addEventListener("vtt:local-roll-feedback", receive);
    return () => window.removeEventListener("vtt:local-roll-feedback", receive);
  }, [sceneId]);

  useEffect(() => {
    if (!latest) return;
    const timeout = window.setTimeout(() => setLatest(null), 1800);
    return () => window.clearTimeout(timeout);
  }, [latest]);

  if (!latest) return null;
  return (
    <RollFeedbackCard
      message={latest}
      animate={preferences.animate}
      onDismiss={() => setLatest(null)}
    />
  );
}
