import type { Panel, Tool } from "../../store/session";
import type { IconName } from "../Icon";

export type ToolDefinition = {
  id: Tool;
  label: string;
  icon: IconName;
  key: string;
  hint: string;
  gm?: boolean;
};

export type PanelDefinition = { id: Panel; label: string; icon: IconName };

export const TABLE_TOOLS: ToolDefinition[] = [
  {
    id: "select",
    label: "Selecionar",
    icon: "cursor",
    key: "V",
    hint: "Arraste um token para mover. Clique duas vezes para abrir a ficha.",
  },
  {
    id: "pan",
    label: "Navegar",
    icon: "hand",
    key: "H",
    hint: "Arraste o mapa para explorar. Use a roda do mouse para aproximar.",
  },
  {
    id: "token",
    label: "Token",
    icon: "token",
    key: "T",
    hint: "Clique no mapa para colocar o personagem selecionado.",
    gm: true,
  },
  {
    id: "wall",
    label: "Parede",
    icon: "wall",
    key: "W",
    hint: "Clique e arraste para desenhar uma parede.",
    gm: true,
  },
  {
    id: "door",
    label: "Porta",
    icon: "door",
    key: "P",
    hint: "Arraste para desenhar. Abra e feche portas no painel Cena.",
    gm: true,
  },
  {
    id: "light",
    label: "Luz",
    icon: "light",
    key: "L",
    hint: "Clique para colocar uma luz. Ela revela a área ao redor.",
    gm: true,
  },
  {
    id: "fog",
    label: "Revelar",
    icon: "fog",
    key: "F",
    hint: "Arraste um retângulo para revelar essa área aos jogadores.",
    gm: true,
  },
  {
    id: "ruler",
    label: "Régua",
    icon: "grid",
    key: "R",
    hint: "Arraste entre dois pontos para medir a distância em pés.",
  },
  {
    id: "circle",
    label: "Círculo",
    icon: "token",
    key: "C",
    hint: "Arraste do centro até a borda para selecionar alvos na área.",
  },
  {
    id: "cone",
    label: "Cone",
    icon: "light",
    key: "N",
    hint: "Arraste na direção do cone para selecionar os alvos atingidos.",
  },
  {
    id: "line",
    label: "Linha",
    icon: "wall",
    key: "I",
    hint: "Arraste uma linha de 5 pés de largura para selecionar alvos.",
  },
  {
    id: "radius",
    label: "Raio",
    icon: "map",
    key: "A",
    hint: "Arraste um raio a partir da origem e selecione os alvos no alcance.",
  },
];

export const SESSION_PANELS: PanelDefinition[] = [
  { id: "chat", label: "Conversa", icon: "chat" },
  { id: "actors", label: "Fichas", icon: "users" },
  { id: "combat", label: "Combate", icon: "swords" },
  { id: "compendium", label: "Biblioteca", icon: "book" },
  { id: "journal", label: "Diário", icon: "journal" },
];
