/** Plain text for 5etools tags and nested data, never interpreted as HTML. */
export function fiveToolsText(value: unknown): string {
  if (typeof value === "number") return String(value);
  if (typeof value === "string") {
    let result = value;
    for (let depth = 0; depth < 10; depth++) {
      const next = result.replace(/\{@(\w+)\s*([^{}]*)\}/g, (_, tag: string, body: string) => {
        const parts = body.split("|");
        if (tag === "h") return "Dano: ";
        if (tag === "hit") return (body.startsWith("-") ? "" : "+") + body;
        return parts[2] || parts[0];
      });
      if (next === result) break;
      result = next;
    }
    return result;
  }
  if (Array.isArray(value)) return value.map(fiveToolsText).filter(Boolean).join(", ");
  if (value && typeof value === "object") {
    const record = value as Record<string, unknown>;
    const content = ["name", "entries", "entry", "items", "special"].filter(
      (key) => record[key] != null,
    );
    if (content.length) return content.map((key) => fiveToolsText(record[key])).join(" ");
    return Object.entries(record)
      .filter(([key]) => !["type", "source", "page"].includes(key))
      .map(([key, item]) => `${key}: ${fiveToolsText(item)}`)
      .join("; ");
  }
  return "";
}
