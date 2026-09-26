import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import type { CatalogEntry, CatalogResults } from "../../lib/catalog";
import { CatalogFilters } from "./CatalogFilters";
import { CatalogEntryCard } from "./CatalogEntryCard";

const noop = () => {};

describe("Biblioteca unificada", () => {
  it("mostra todas as categorias e o título da fonte mantendo seu código", () => {
    const results: CatalogResults = {
      data: [],
      current_page: 1,
      last_page: 1,
      total: 0,
      sources: ["PHB"],
    };
    const html = renderToStaticMarkup(
      <CatalogFilters
        optional={false}
        kind="all"
        edition="5e-2014"
        source=""
        level=""
        query=""
        results={results}
        sourceNames={{ PHB: "Livro do Jogador" }}
        target=""
        editableActors={[]}
        showTarget={false}
        onOptional={noop}
        onKind={noop}
        onEdition={noop}
        onSource={noop}
        onLevel={noop}
        onQuery={noop}
        onTarget={noop}
      />,
    );
    expect(html).toContain("Todos os conteúdos");
    expect(html).toContain("Livro do Jogador · PHB");
    expect(html).toContain("Espécies / raças");
    expect(html).not.toContain("5etools · texto original");
  });

  it("identifica materialização, origem, incompatibilidade de edição e mídia indisponível", () => {
    const entry: CatalogEntry = {
      id: 2,
      slug: "sword",
      kind: "items",
      name: "Sword",
      source: "XPHB",
      edition: "5e-2024",
      inCampaign: true,
      data: { raw: {}, sourceName: "Player Handbook 2024", description: "A blade" },
    };
    const html = renderToStaticMarkup(
      <CatalogEntryCard
        entry={entry}
        kind="items"
        expanded
        role="player"
        ruleset="5e-2014"
        campaignId={1}
        busy={false}
        canAdd={false}
        onToggle={noop}
        onShare={noop}
        onAdd={noop}
      />,
    );
    expect(html).toContain("Na campanha");
    expect(html).toContain("Player Handbook 2024");
    expect(html).toContain("Este verbete é de outra edição");
    expect(html).toContain("Arte indisponível");
    expect(html).toContain("disabled");
  });
});
