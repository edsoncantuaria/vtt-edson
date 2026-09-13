import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { FiveToolsEntries } from "./FiveToolsEntries";
import { fiveToolsText } from "../lib/fiveToolsText";

describe("5etools reading", () => {
  it("preserves table rows, roll ranges, lists and nested headings", () => {
    const html = renderToStaticMarkup(
      <FiveToolsEntries
        value={[
          { type: "entries", name: "Travel", entries: [{ type: "list", items: ["Walk"] }] },
          {
            type: "table",
            caption: "Encounters",
            colLabels: ["d6", "Result"],
            rows: [
              [{ type: "cell", roll: { min: 1, max: 3 } }, "Goblin"],
              { type: "row", row: [4, "Dragon"] },
            ],
          },
        ]}
      />,
    );
    expect(html).toContain("<h4>Travel</h4>");
    expect(html).toContain("<li><p>Walk</p></li>");
    expect(html).toContain("1–3");
    expect(html).toContain("<td><p>4</p></td>");
    expect(html).toContain("<caption>Encounters</caption>");
  });
  it("escapes untrusted markup and resolves nested reference labels", () => {
    expect(fiveToolsText("{@b Use {@spell fireball|PHB|Fireball}}")).toBe("Use Fireball");
    const html = renderToStaticMarkup(<FiveToolsEntries value="<script>alert(1)</script>" />);
    expect(html).not.toContain("<script>");
    expect(html).toContain("&lt;script&gt;");
  });
});
