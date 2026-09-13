import type { CatalogEntry, CatalogKind, HomebrewCatalogEntry } from "../../lib/catalog";
import { Icon } from "../Icon";
import { CatalogDetailValue } from "./CatalogDetail";

export function HomebrewSection({
  entries,
  kind,
  expandedId,
  busy,
  showAdd,
  canAdd,
  toCatalogEntry,
  onToggle,
  onAdd,
}: {
  entries: HomebrewCatalogEntry[];
  kind: CatalogKind;
  expandedId: number | null;
  busy: boolean;
  showAdd: boolean;
  canAdd: boolean;
  toCatalogEntry: (entry: HomebrewCatalogEntry) => CatalogEntry;
  onToggle: (id: number) => void;
  onAdd: (entry: CatalogEntry) => void;
}) {
  if (!entries.length) return null;
  return (
    <section className="compendium-homebrew">
      <h3>Homebrew habilitado</h3>
      <p className="edition-note">
        Conteúdo versionado desta campanha. Alterações no pacote não sobrescrevem fichas que já
        receberam uma cópia.
      </p>
      {entries.map((homebrew) => {
        const entry = toCatalogEntry(homebrew);
        const expanded = expandedId === homebrew.homebrewId;
        return (
          <article className="compendium-entry" key={homebrew.homebrewId}>
            <button
              className="compendium-entry__head"
              aria-expanded={expanded}
              onClick={() => onToggle(homebrew.homebrewId)}
            >
              <span className="entry-icon">
                <Icon
                  name={kind === "spells" ? "spark" : kind === "items" ? "shield" : "swords"}
                  size={20}
                />
              </span>
              <span>
                <b>{homebrew.name}</b>
                <small>
                  {homebrew.package?.name ?? "Homebrew"} · v{homebrew.version}
                </small>
              </span>
              <Icon name="chevron" size={15} />
            </button>
            {expanded && (
              <div className="compendium-detail">
                <p className="catalog-origin">
                  Homebrew da campanha · pacote {homebrew.package?.name ?? "sem nome"} v
                  {homebrew.package?.version ?? homebrew.version}
                </p>
                <div className="catalog-prose">
                  {String(
                    entry.data.description ??
                      entry.data.desc ??
                      "Verbete homebrew estruturado desta mesa.",
                  )}
                </div>
                <details>
                  <summary>Dados e referências</summary>
                  <CatalogDetailValue value={entry.data} />
                </details>
                {showAdd && !(["books", "adventures"] as CatalogKind[]).includes(kind) && (
                  <button
                    className="primary"
                    disabled={busy || !canAdd}
                    onClick={() => onAdd(entry)}
                  >
                    <Icon name="plus" size={15} />
                    {kind === "monsters" ? "Adicionar à cena" : "Adicionar à ficha"}
                  </button>
                )}
              </div>
            )}
          </article>
        );
      })}
    </section>
  );
}
