import { Icon } from "../Icon";
import { Modal } from "../Modal";
import type { ToolDefinition } from "./tableViewConfig";

export function InviteDialog({
  roomCode,
  copied,
  onClose,
  onCopy,
}: {
  roomCode: string | null;
  copied: boolean;
  onClose: () => void;
  onCopy: () => void;
}) {
  return (
    <Modal title="A aventura é melhor em grupo" onClose={onClose}>
      <div className="invite-content">
        <p>
          Seus jogadores entram na conta e escolhem <b>Entrar com código</b>.
        </p>
        <span className="invite-code">{roomCode}</span>
        <button className="primary" onClick={onCopy}>
          <Icon name={copied ? "check" : "copy"} size={17} />
          {copied ? "Código copiado" : "Copiar código"}
        </button>
        <small>Depois de entrar, a mesa fica na biblioteca de cada participante.</small>
      </div>
    </Modal>
  );
}

export function HelpDialog({
  gm,
  tools,
  onClose,
}: {
  gm: boolean;
  tools: ToolDefinition[];
  onClose: () => void;
}) {
  return (
    <Modal title="Menos cliques, mais aventura" onClose={onClose}>
      <div className="help-content">
        <p>
          {gm
            ? "Prepare a cena, coloque fichas no mapa e revele o mundo para seus jogadores."
            : "Você pode mover seus tokens, editar suas fichas e rolar dados com o grupo."}
        </p>
        {tools.map((tool) => (
          <div key={tool.id}>
            <Icon name={tool.icon} size={18} />
            <span>
              <b>{tool.label}</b>
              <small>{tool.hint}</small>
            </span>
            <kbd>{tool.key}</kbd>
          </div>
        ))}
        <p>
          A roda do mouse ajusta o zoom. Arraste com o botão direito para navegar. O nevoeiro é
          revelado manualmente pelo mestre.
        </p>
      </div>
    </Modal>
  );
}
