import type { ReactNode } from "react";
import { fiveToolsText } from "../lib/fiveToolsText";

/** Render upstream data as React nodes; upstream markup never becomes HTML. */
export function FiveToolsEntries({ value }: { value: unknown }): ReactNode {
  if (value == null) return null;
  if (typeof value === "string" || typeof value === "number") return <p>{fiveToolsText(value)}</p>;
  if (Array.isArray(value))
    return value.map((entry, i) => <FiveToolsEntries key={i} value={entry} />);
  if (typeof value !== "object") return null;
  const entry = value as Record<string, unknown>;
  const title = typeof entry.name === "string" ? fiveToolsText(entry.name) : undefined;
  if (entry.type === "table") {
    const rows = Array.isArray(entry.rows) ? entry.rows : [];
    return (
      <div
        className="five-tools-table"
        tabIndex={0}
        role="region"
        aria-label={fiveToolsText(entry.caption) || "Tabela"}
      >
        <table>
          {entry.caption != null && <caption>{fiveToolsText(entry.caption)}</caption>}
          {Array.isArray(entry.colLabels) && (
            <thead>
              <tr>
                {entry.colLabels.map((label, i) => (
                  <th scope="col" key={i}>
                    {fiveToolsText(label)}
                  </th>
                ))}
              </tr>
            </thead>
          )}
          <tbody>
            {rows.map((row, i) => {
              const cells = Array.isArray(row) ? row : row?.row;
              return (
                <tr key={i}>
                  {Array.isArray(cells) &&
                    cells.map((cell, j) => (
                      <td key={j}>
                        <FiveToolsEntries value={cell} />
                      </td>
                    ))}
                </tr>
              );
            })}
          </tbody>
        </table>
        <FiveToolsEntries value={entry.footnotes} />
      </div>
    );
  }
  if (entry.type === "list")
    return (
      <ul>
        {Array.isArray(entry.items) &&
          entry.items.map((item, i) => (
            <li key={i}>
              <FiveToolsEntries value={item} />
            </li>
          ))}
      </ul>
    );
  if (entry.type === "image")
    return (
      <figure>
        <figcaption>
          {fiveToolsText(entry.title) || "Ilustração"} · imagem ainda não instalada no acervo local.
        </figcaption>
      </figure>
    );
  if (entry.type === "statblock")
    return (
      <p>
        <strong>{fiveToolsText(entry.name) || "Bloco de estatísticas"}</strong> · consulte no
        compêndio{typeof entry.source === "string" ? ` (${entry.source})` : ""}.
      </p>
    );
  if (entry.roll && typeof entry.roll === "object") {
    const roll = entry.roll as Record<string, unknown>;
    return <>{String(roll.exact ?? `${roll.min ?? ""}–${roll.max ?? ""}`)}</>;
  }
  return (
    <section
      className={
        entry.type === "inset" || entry.type === "insetReadaloud" ? "five-tools-inset" : undefined
      }
    >
      {title && <h4>{title}</h4>}
      <FiveToolsEntries
        value={entry.entries ?? entry.entry ?? entry.items ?? entry.tables ?? entry.images}
      />
    </section>
  );
}
