import type { ReactNode } from "react";

const LABELS: Record<string, string> = {
  desc: "Descrição",
  description: "Descrição",
  name: "Nome",
  level: "Círculo",
  school: "Escola",
  castingTime: "Conjuração",
  casting_time: "Conjuração",
  range: "Alcance",
  components: "Componentes",
  duration: "Duração",
  higher_level: "Em níveis superiores",
  damage: "Dano",
  armorClass: "Classe de armadura",
  armor_class: "Classe de armadura",
  armor_desc: "Armadura",
  ac: "Classe de armadura",
  hp: "Pontos de vida",
  hit_points: "Pontos de vida",
  hit_dice: "Dados de vida",
  speed: "Deslocamento",
  walk: "Caminhada",
  fly: "Voo",
  swim: "Natação",
  climb: "Escalada",
  abilities: "Atributos",
  str: "Força",
  dex: "Destreza",
  con: "Constituição",
  int: "Inteligência",
  wis: "Sabedoria",
  cha: "Carisma",
  strength: "Força",
  dexterity: "Destreza",
  constitution: "Constituição",
  intelligence: "Inteligência",
  wisdom: "Sabedoria",
  charisma: "Carisma",
  senses: "Sentidos",
  actions: "Ações",
  special_abilities: "Características",
  legendary_actions: "Ações lendárias",
  reactions: "Reações",
  alignment: "Alinhamento",
  size: "Tamanho",
  type: "Tipo",
  category: "Categoria",
  weight: "Peso",
  cost: "Custo",
  properties: "Propriedades",
  effect: "Efeito",
  strengthReq: "Força mínima",
  vulnerabilities: "Vulnerabilidades",
  damage_vulnerabilities: "Vulnerabilidades",
  damage_resistances: "Resistências",
  damage_immunities: "Imunidades",
  condition_immunities: "Imunidades a condições",
  languages: "Idiomas",
  challenge_rating: "Nível de desafio",
  skills: "Perícias",
  material: "Material",
  ritual: "Ritual",
  concentration: "Concentração",
};

export function CatalogDetailValue({ value }: { value: unknown }): ReactNode {
  if (value === null || value === undefined || value === "") return "—";
  if (Array.isArray(value))
    return (
      <ul>
        {value.map((item, index) => (
          <li key={index}>
            <CatalogDetailValue value={item} />
          </li>
        ))}
      </ul>
    );
  if (typeof value === "object") {
    return (
      <dl>
        {Object.entries(value).map(([key, nested]) => (
          <div key={key}>
            <dt>{LABELS[key] ?? key.replaceAll("_", " ")}</dt>
            <dd>
              <CatalogDetailValue value={nested} />
            </dd>
          </div>
        ))}
      </dl>
    );
  }
  return typeof value === "boolean" ? (value ? "Sim" : "Não") : String(value);
}
