import { useCallback, useEffect, useState, type FormEvent } from "react";
import { api } from "../lib/api";
import { useSession } from "../store/session";

type Recipient = { id: number; name: string };
type PrivateMessage = {
  id: number;
  kind: "text" | "roll";
  text: string | null;
  formula: string | null;
  total: number | null;
  detail: string | null;
  created_at: string;
  sender: { id: number; name: string };
  recipient: { id: number; name: string };
};

export function PrivateChat() {
  const { sceneId, user, setError } = useSession();
  const [messages, setMessages] = useState<PrivateMessage[]>([]);
  const [recipients, setRecipients] = useState<Recipient[]>([]);
  const [recipient, setRecipient] = useState("gm");
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState(false);
  const load = useCallback(async () => {
    if (!sceneId) return;
    try {
      const result = await api<{ messages: PrivateMessage[]; recipients: Recipient[] }>(
        `/scenes/${sceneId}/private-messages`,
      );
      setMessages(result.messages);
      setRecipients(result.recipients);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Falha ao carregar mensagens privadas.");
    }
  }, [sceneId, setError]);
  useEffect(() => {
    if (!open) return;
    void load();
    const timer = window.setInterval(() => void load(), 5000);
    return () => window.clearInterval(timer);
  }, [open, load]);
  async function send(event: FormEvent) {
    event.preventDefault();
    if (!sceneId || !text.trim() || busy) return;
    setBusy(true);
    try {
      await api(`/scenes/${sceneId}/private-messages`, {
        method: "POST",
        body: JSON.stringify(
          recipient === "gm"
            ? { audience: "gm", text: text.trim() }
            : { audience: "user", recipientUserId: Number(recipient), text: text.trim() },
        ),
      });
      setText("");
      await load();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Falha ao enviar mensagem privada.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <details className="private-chat" onToggle={(event) => setOpen(event.currentTarget.open)}>
      <summary>Privado · sussurros e rolagens</summary>
      <p>
        Somente remetente e destinatário recebem este histórico. Use <code>/roll 1d20+5</code> para
        uma rolagem privada.
      </p>
      <div className="private-chat__log">
        {messages.map((message) => (
          <article key={message.id}>
            <strong>
              {message.sender.id === user?.id ? "Você" : message.sender.name} →{" "}
              {message.recipient.id === user?.id ? "você" : message.recipient.name}
            </strong>
            {message.kind === "roll" ? (
              <p>
                <code>{message.formula}</code> = <b>{message.total}</b>
                <small>{message.detail}</small>
              </p>
            ) : (
              <p>{message.text}</p>
            )}
          </article>
        ))}
      </div>
      <form onSubmit={send}>
        <label>
          Destinatário
          <select value={recipient} onChange={(e) => setRecipient(e.target.value)}>
            <option value="gm">Mestre</option>
            {recipients
              .filter((item) => item.id !== user?.id)
              .map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
          </select>
        </label>
        <textarea
          rows={2}
          maxLength={1000}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="Mensagem privada ou /roll…"
        />
        <button disabled={busy || !text.trim()}>Enviar privado</button>
      </form>
    </details>
  );
}
