import type { FormEvent } from "react";
import type { Ruleset } from "../../store/session";
import { Icon } from "../Icon";
import { Modal } from "../Modal";

export type RoomModalKind = "create" | "join";

export function RoomModal({
  kind,
  busy,
  error,
  library,
  setLibrary,
  campaignName,
  setCampaignName,
  ruleset,
  setRuleset,
  joinCode,
  setJoinCode,
  onClose,
  onCreate,
  onJoin,
}: {
  kind: RoomModalKind;
  busy: boolean;
  error: string | null;
  library: string;
  setLibrary: (value: string) => void;
  campaignName: string;
  setCampaignName: (value: string) => void;
  ruleset: Ruleset;
  setRuleset: (value: Ruleset) => void;
  joinCode: string;
  setJoinCode: (value: string) => void;
  onClose: () => void;
  onCreate: (event: FormEvent<HTMLFormElement>) => void;
  onJoin: () => void;
}) {
  return (
    <Modal
      title={kind === "create" ? "Uma nova aventura" : "Seu grupo está esperando"}
      onClose={onClose}
    >
      <form
        className="room-form"
        onSubmit={
          kind === "create"
            ? onCreate
            : (event) => {
                event.preventDefault();
                onJoin();
              }
        }
      >
        <p>
          {kind === "create"
            ? "Você será o mestre desta mesa. Depois, é só compartilhar o código com seu grupo."
            : "Cole o código enviado pelo mestre. A mesa ficará salva na sua conta."}
        </p>
        {kind === "create" && (
          <label>
            Biblioteca inicial
            <select value={library} onChange={(event) => setLibrary(event.target.value)}>
              <option value="core">Livros básicos da edição</option>
              <option value="all">Todo o acervo disponível</option>
            </select>
            <small>Você pode escolher fontes adicionais em Preparar cena.</small>
          </label>
        )}
        {kind === "create" ? (
          <>
            <label>
              Nome da campanha
              <input
                value={campaignName}
                onChange={(event) => setCampaignName(event.target.value)}
                autoFocus
                required
                maxLength={120}
                placeholder="Ex.: As Crônicas de Valdora"
              />
            </label>
            <label>
              Livro de regras
              <select
                value={ruleset}
                onChange={(event) => setRuleset(event.target.value as Ruleset)}
              >
                <option value="5e-2024">D&D 5e · Revisão de 2024</option>
                <option value="5e-2014">D&D 5e · Edição de 2014</option>
              </select>
            </label>
            <p className="muted">
              A edição orienta a biblioteca inicial e o assistente de personagens.
            </p>
          </>
        ) : (
          <label>
            Código da mesa
            <input
              className="room-code-input"
              autoFocus
              value={joinCode}
              onChange={(event) =>
                setJoinCode(event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ""))
              }
              required
              minLength={6}
              maxLength={8}
              placeholder="ABC123"
              autoComplete="off"
            />
          </label>
        )}
        {error && (
          <p className="notice notice--error" role="alert">
            {error}
          </p>
        )}
        <button className="primary" disabled={busy}>
          {busy
            ? "Preparando a mesa…"
            : kind === "create"
              ? "Criar e abrir mesa"
              : "Entrar na mesa"}
          <Icon name="arrow" size={17} />
        </button>
      </form>
    </Modal>
  );
}
