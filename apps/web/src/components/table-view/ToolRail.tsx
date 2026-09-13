import type { Tool } from "../../store/session";
import { Icon } from "../Icon";
import type { ToolDefinition } from "./tableViewConfig";

export function ToolRail({
  tools,
  activeTool,
  gm,
  onTool,
  onScene,
  onHelp,
}: {
  tools: ToolDefinition[];
  activeTool: Tool;
  gm: boolean;
  onTool: (tool: Tool) => void;
  onScene: () => void;
  onHelp: () => void;
}) {
  return (
    <nav className="tool-rail" aria-label="Ferramentas do mapa">
      {tools.map((tool, index) => (
        <button
          key={tool.id}
          className={`${activeTool === tool.id ? "active " : ""}${index === 2 ? "tool-divider" : ""}`}
          aria-label={`${tool.label} (${tool.key})`}
          aria-pressed={activeTool === tool.id}
          title={`${tool.label} · ${tool.key}`}
          onClick={() => onTool(tool.id)}
        >
          <Icon name={tool.icon} size={21} />
          <span>{tool.label}</span>
        </button>
      ))}
      <div className="tool-rail__bottom">
        {gm && (
          <button onClick={onScene} aria-label="Configurar cena">
            <Icon name="settings" />
            <span>Cena</span>
          </button>
        )}
        <button onClick={onHelp} aria-label="Ajuda e atalhos">
          <Icon name="help" />
          <span>Ajuda</span>
        </button>
      </div>
    </nav>
  );
}
