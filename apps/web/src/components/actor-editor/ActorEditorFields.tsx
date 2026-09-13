import type { ActorSystem } from "@vtt/core";
import type { ReactNode } from "react";

export type MutateActorSystem = (change: (system: ActorSystem) => void) => void;

export function NumberField({
  label,
  value,
  onChange,
  min = 0,
  max = 999,
}: {
  label: string;
  value: number;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
}) {
  return (
    <label>
      {label}
      <input
        type="number"
        required
        min={min}
        max={max}
        value={value}
        onChange={(event) => onChange(Number(event.target.value))}
      />
    </label>
  );
}

export function EditorSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="editor-section">
      <h3>{title}</h3>
      {children}
    </section>
  );
}
