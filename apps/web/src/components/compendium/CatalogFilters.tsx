import { CATALOG_KINDS, type CatalogKind, type CatalogResults } from "../../lib/catalog";
import { Icon } from "../Icon";

export function CatalogFilters({
  optional,
  kind,
  edition,
  source,
  level,
  query,
  results,
  target,
  editableActors,
  showTarget,
  onOptional,
  onKind,
  onEdition,
  onSource,
  onLevel,
  onQuery,
  onTarget,
}: {
  optional: boolean;
  kind: CatalogKind;
  edition: string;
  source: string;
  level: string;
  query: string;
  results: CatalogResults | null;
  target: string;
  editableActors: { id: number; name: string }[];
  showTarget: boolean;
  onOptional: (value: boolean) => void;
  onKind: (kind: CatalogKind) => void;
  onEdition: (edition: string) => void;
  onSource: (source: string) => void;
  onLevel: (level: string) => void;
  onQuery: (query: string) => void;
  onTarget: (target: string) => void;
}) {
  return (
    <>
      <details>
        <summary>Conteúdos opcionais</summary>
        <label>
          <input
            type="checkbox"
            checked={optional}
            onChange={(event) => onOptional(event.target.checked)}
          />{" "}
          Mostrar bastiões, veículos, baralhos e outros sistemas
        </label>
        <p>
          As fontes permitidas pela campanha continuam sendo respeitadas. Estes verbetes são
          referências; não ativam automações adicionais.
        </p>
      </details>
      <div className="catalog-filters">
        <label>
          Categoria
          <select value={kind} onChange={(event) => onKind(event.target.value as CatalogKind)}>
            {CATALOG_KINDS.filter((item) => optional || !item.optional).map((item) => (
              <option value={item.id} key={item.id}>
                {item.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Regras
          <select value={edition} onChange={(event) => onEdition(event.target.value)}>
            <option value="5e-2014">D&D 5e · 2014</option>
            <option value="5e-2024">D&D revisado · 2024</option>
          </select>
        </label>
        <label>
          Livro / fonte
          <select value={source} onChange={(event) => onSource(event.target.value)}>
            <option value="">Todas as fontes</option>
            {results?.sources.map((item) => (
              <option key={item}>{item}</option>
            ))}
          </select>
        </label>
        {kind === "spells" && (
          <label>
            Círculo
            <select value={level} onChange={(event) => onLevel(event.target.value)}>
              <option value="">Todos</option>
              {Array.from({ length: 10 }, (_, index) => (
                <option key={index} value={index}>
                  {index === 0 ? "Truques" : `${index}º círculo`}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      <label className="search-field">
        <Icon name="search" size={17} />
        <input
          aria-label="Buscar no compêndio"
          placeholder={`Buscar ${CATALOG_KINDS.find((item) => item.id === kind)!.label.toLowerCase()}…`}
          value={query}
          onChange={(event) => onQuery(event.target.value)}
        />
      </label>
      <p className="edition-note">
        5etools · texto original dos livros. Versões de 2014 e 2024 são mantidas separadamente.
      </p>
      {showTarget && (
        <label className="compendium-target">
          Adicionar à ficha
          <select value={target} onChange={(event) => onTarget(event.target.value)}>
            <option value="">Escolher sua ficha…</option>
            {editableActors.map((actor) => (
              <option key={actor.id} value={actor.id}>
                {actor.name}
              </option>
            ))}
          </select>
        </label>
      )}
    </>
  );
}
