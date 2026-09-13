import { useEffect, useRef, useState, type FormEvent } from "react";
import { isValidDiceFormula, type ChatMessage } from "@vtt/core";
import { api } from "../lib/api";
import { useSession } from "../store/session";
import { updateScene } from "../lib/scene";
import { DamageApplication } from "./DamageApplication";
import { UndoAction } from "./UndoAction";
import { Icon } from "./Icon";
import { PrivateChat } from "./PrivateChat";

export function ChatPanel() {
  const { state, sceneId, setError, user } = useSession();
  const [history, setHistory] = useState<ChatMessage[] | null>(null);
  const [historyPage, setHistoryPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [historyBusy, setHistoryBusy] = useState(false);
  async function loadHistory(page: number) {
    setHistoryBusy(true);
    try {
      const result = await api<{ messages: ChatMessage[]; lastPage: number }>(
        `/scenes/${sceneId}/actions?page=${page}`,
      );
      setHistory(result.messages);
      setHistoryPage(page);
      setLastPage(result.lastPage);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Falha ao consultar histórico.");
    } finally {
      setHistoryBusy(false);
    }
  }
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const log = useRef<HTMLDivElement>(null);
  const stick = useRef(true);
  useEffect(() => {
    if (stick.current && log.current) log.current.scrollTop = log.current.scrollHeight;
  }, [state.chat]);
  async function send(e: FormEvent) {
    e.preventDefault();
    if (!text.trim() || !sceneId || busy) return;
    const message = text.trim();
    if (message.toLowerCase().startsWith("/roll ") && !isValidDiceFormula(message.slice(6))) {
      setError("Use uma fórmula como 1d20+4 ou 2d20kh1 para vantagem.");
      return;
    }
    setBusy(true);
    try {
      await updateScene(sceneId, "/chat", { text: message });
      setText("");
      stick.current = true;
    } catch (e) {
      setError(e instanceof Error ? e.message : "Mensagem não enviada. Tente novamente.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="chat-panel">
      <div className="chat-history-controls">
        <button
          disabled={historyBusy}
          onClick={() => (history ? setHistory(null) : void loadHistory(1))}
        >
          {history ? "Voltar à conversa" : "Histórico de ações"}
        </button>
        {history && (
          <>
            <button
              disabled={historyBusy || historyPage <= 1}
              onClick={() => void loadHistory(historyPage - 1)}
            >
              Mais recentes
            </button>
            <span>
              {historyPage}/{lastPage}
            </span>
            <button
              disabled={historyBusy || historyPage >= lastPage}
              onClick={() => void loadHistory(historyPage + 1)}
            >
              Mais antigas
            </button>
          </>
        )}
      </div>
      <div
        className="chat-log"
        ref={log}
        role="log"
        aria-label="Conversa e rolagens"
        onScroll={() => {
          const el = log.current!;
          stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 70;
        }}
      >
        {(history ?? state.chat).length === 0 && (
          <div className="chat-welcome">
            <Icon name="spark" size={26} />
            <h3>A história começa aqui.</h3>
            <p>Converse com o grupo e acompanhe cada rolagem. Que os dados estejam a seu favor.</p>
            <span>INÍCIO DA CONVERSA</span>
          </div>
        )}
        {(history ?? state.chat).map((m) => (
          <article
            className={
              "message " +
              (m.type === "roll" || m.type === "action" ? "message--roll " : "") +
              (m.userId === user?.id ? "message--own" : "")
            }
            key={m.id}
          >
            <div className="message__heading">
              <span className="avatar">{m.userName.slice(0, 2).toUpperCase()}</span>
              <strong>{m.userName}</strong>
              <time dateTime={m.createdAt}>
                {new Date(m.createdAt).toLocaleTimeString("pt-BR", {
                  hour: "2-digit",
                  minute: "2-digit",
                })}
              </time>
            </div>
            {m.type === "roll" || m.type === "action" ? (
              <div className="roll-result">
                <div>
                  <span>{m.label || "Rolagem de dados"}</span>
                  <code>{m.formula}</code>
                  <small>{m.detail}</small>
                </div>
                <strong className={m.critical ? "critical" : m.fumble ? "fumble" : ""}>
                  {m.total}
                </strong>
                {m.rolls &&
                  m.rolls.length > 1 &&
                  m.rolls.slice(1).map((roll, index) => (
                    <div key={index}>
                      <b>Dano: {roll.total}</b>
                      <code>{roll.formula}</code>
                      <small>{roll.detail}</small>
                    </div>
                  ))}
                {m.critical && <b className="roll-tag">20 natural</b>}
                {m.houseRules?.length ? (
                  <small className="roll-tag">Regras da mesa: {m.houseRules.join(", ")}</small>
                ) : null}
                {m.fumble && <b className="roll-tag">1 natural</b>}
                {m.type === "action" && (
                  <b className="roll-tag">
                    {m.actionKind === "spell" ? "Conjuração compartilhada" : "Ação compartilhada"}
                  </b>
                )}
              </div>
            ) : (
              <p>{m.text}</p>
            )}
            {(m.save || m.rolls?.some((roll) => roll.kind === "damage")) && (
              <DamageApplication message={m} />
            )}
            {m.sourceActorId && <UndoAction messageId={m.id} actorId={m.sourceActorId} />}
          </article>
        ))}
      </div>
      <form className="chat-compose" onSubmit={send}>
        <label className="sr-only" htmlFor="chat-message">
          Mensagem para a mesa
        </label>
        <textarea
          id="chat-message"
          rows={2}
          maxLength={1000}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="O que seu personagem faz?"
          onKeyDown={(e) => {
            if (e.key === "Enter" && !e.shiftKey) {
              e.preventDefault();
              e.currentTarget.form?.requestSubmit();
            }
          }}
        />
        <div>
          <span>Enter envia · Shift + Enter quebra linha</span>
          <button
            className="primary icon-button"
            type="submit"
            aria-label="Enviar mensagem"
            disabled={busy || !text.trim()}
          >
            <Icon name="arrow" size={18} />
          </button>
        </div>
        <small>
          Também vale: <code>/roll 2d6+3</code>
        </small>
      </form>
      <PrivateChat />
    </div>
  );
}
