import { isManagerRole, type Role } from "@vtt/core";
import type { CatalogEntry, CatalogKind } from "../../lib/catalog";
import { CATALOG_KINDS } from "../../lib/catalog";
import { AdventureImport } from "../AdventureImport";
import { CatalogImage } from "../CatalogImage";
import { FiveToolsEntries } from "../FiveToolsEntries";
import { Icon } from "../Icon";
import { CatalogDetailValue } from "./CatalogDetail";

function subtitle(entry: CatalogEntry, kind: CatalogKind): string {
  if (kind === "spells") return entry.level === 0 ? "Truque" : `${entry.level}º círculo`;
  if (kind === "monsters") return `ND ${String(entry.data.challenge_rating ?? "—")}`;
  if (entry.data.type === "weapon") return "Arma";
  if (entry.data.type === "armor") return "Armadura";
  return CATALOG_KINDS.find((category) => category.id === kind)?.label ?? kind;
}

export function CatalogEntryCard({
  entry,
  kind,
  expanded,
  role,
  campaignId,
  busy,
  canAdd,
  onToggle,
  onShare,
  onAdd,
}: {
  entry: CatalogEntry;
  kind: CatalogKind;
  expanded: boolean;
  role: Role | null;
  campaignId: number | null;
  busy: boolean;
  canAdd: boolean;
  onToggle: () => void;
  onShare: () => void;
  onAdd: () => void;
}) {
  const handbook = kind === "books" && ["PHB", "XPHB"].includes(entry.source);
  const referenceOnly = ["books", "adventures", "cards", "legendary-groups", "rules"].includes(
    kind,
  );
  const campaignTool = ["encounters", "loot"].includes(kind);
  const subsystem = [
    "bastions",
    "vehicles",
    "decks",
    "recipes",
    "psionics",
    "rewards",
    "deities",
    "languages",
    "hazards",
    "objects",
    "cults",
  ].includes(kind);
  return (
    <article className="compendium-entry">
      <button className="compendium-entry__head" aria-expanded={expanded} onClick={onToggle}>
        <span className="entry-icon">
          <CatalogImage entry={entry} thumbnail>
            <Icon
              name={kind === "spells" ? "spark" : kind === "items" ? "shield" : "swords"}
              size={20}
            />
          </CatalogImage>
        </span>
        <span>
          <b>{entry.name}</b>
          <small>
            {entry.source} · {entry.edition.slice(-4)}
          </small>
          <small>{subtitle(entry, kind)}</small>
        </span>
        <Icon name="chevron" size={15} />
      </button>
      {expanded && (
        <div className="compendium-detail">
          <CatalogImage entry={entry} />
          {kind === "adventures" && isManagerRole(role) && campaignId && (
            <AdventureImport entry={entry} campaignId={campaignId} />
          )}
          <p className="catalog-origin">
            {String(entry.data.sourceName ?? entry.source)} ·{" "}
            {entry.edition === "5e-2024" ? "2024" : "2014"}
            {entry.data.page ? ` · p. ${String(entry.data.page)}` : ""}
          </p>
          <div className="catalog-prose">
            {referenceOnly ? (
              <FiveToolsEntries value={entry.data.raw} />
            ) : (
              String(entry.data.description || "Consulte os dados abaixo para este verbete.")
            )}
          </div>
          <details>
            <summary>Dados e referências</summary>
            <CatalogDetailValue
              value={Object.fromEntries(
                Object.entries(entry.data).filter(([key]) => !["description", "raw"].includes(key)),
              )}
            />
          </details>
          {referenceOnly && isManagerRole(role) && !handbook && (
            <button disabled={busy} onClick={onShare}>
              {entry.shared ? "Remover acesso do grupo" : "Compartilhar capítulo com o grupo"}
            </button>
          )}
          {handbook && <p>Livro do Jogador · disponível para todos na mesa.</p>}
          {!referenceOnly && (kind !== "monsters" || isManagerRole(role)) && (
            <button className="primary" disabled={busy || !canAdd} onClick={onAdd}>
              <Icon name="plus" size={15} />
              {busy
                ? "Adicionando…"
                : kind === "monsters"
                  ? "Adicionar à cena"
                  : campaignTool
                    ? "Integrar à campanha"
                    : subsystem
                      ? "Ativar na campanha"
                      : "Adicionar à ficha"}
            </button>
          )}
        </div>
      )}
    </article>
  );
}
