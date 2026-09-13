import type { Actor, SceneState } from "@vtt/core";
import { useSession } from "../../store/session";
import { Icon } from "../Icon";
import type { SceneAction } from "./SceneEnvironmentSections";

export function TokensSection({
  tokens,
  actors,
  actorId,
  setActorId,
  busy,
  center,
  action,
}: {
  tokens: SceneState["tokens"];
  actors: Actor[];
  actorId: string;
  setActorId: (value: string) => void;
  busy: boolean;
  center: () => { x: number; y: number };
  action: SceneAction;
}) {
  return (
    <section>
      <h3>
        <Icon name="users" size={17} />
        Tokens na cena <span>{tokens.length}</span>
      </h3>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          const actor = actors.find((item) => item.id === Number(actorId));
          if (!actor) return;
          const rawSize = Array.isArray(actor.system.statBlock?.size)
            ? actor.system.statBlock.size[0]
            : "M";
          const size =
            ({ T: 0.5, S: 1, M: 1, L: 2, H: 3, G: 4 } as Record<string, number>)[String(rawSize)] ??
            1;
          void action("/tokens", {
            ...center(),
            name: actor.name,
            actorId: actor.id,
            ownerUserId: actor.ownerUserId,
            size,
          });
        }}
      >
        <label>
          Adicionar uma ficha
          <select value={actorId} onChange={(event) => setActorId(event.target.value)}>
            <option value="">Escolher personagem ou criatura</option>
            {actors.map((actor) => (
              <option key={actor.id} value={actor.id}>
                {actor.name}
              </option>
            ))}
          </select>
        </label>
        <button disabled={!actorId || busy}>
          <Icon name="plus" size={15} />
          Colocar no centro do mapa
        </button>
      </form>
      {tokens.map((token) => (
        <div className="scene-object" key={token.id}>
          <button
            disabled={busy}
            onClick={() => void action("/tokens", { ...token, hidden: !token.hidden })}
          >
            {token.hidden ? "Revelar" : "Ocultar"}
          </button>
          {token.hidden && (
            <label>
              CD Furtividade
              <input
                type="number"
                min={1}
                max={40}
                value={token.stealthDc ?? ""}
                placeholder="GM-only"
                onChange={(event) =>
                  void action("/tokens", {
                    ...token,
                    stealthDc: event.target.value ? Number(event.target.value) : null,
                  })
                }
              />
            </label>
          )}
          <details>
            <summary>Aparência de {token.name}</summary>
            <form
              onSubmit={(event) => {
                event.preventDefault();
                const fields = new FormData(event.currentTarget);
                void action("/tokens", {
                  ...token,
                  size: Number(fields.get("size")),
                  appearance: {
                    border: fields.get("border"),
                    background: fields.get("background"),
                    zoom: Number(fields.get("zoom")),
                    x: Number(fields.get("x")),
                    y: Number(fields.get("y")),
                  },
                });
              }}
              key={JSON.stringify([token.size, token.appearance])}
            >
              <label>
                Tamanho (quadrados)
                <input
                  name="size"
                  type="number"
                  min={0.25}
                  max={20}
                  step={0.25}
                  defaultValue={token.size}
                  required
                />
              </label>
              <label>
                Borda
                <input
                  name="border"
                  type="color"
                  defaultValue={token.appearance?.border ?? "#d9a441"}
                />
              </label>
              <label>
                Fundo
                <input
                  name="background"
                  type="color"
                  defaultValue={token.appearance?.background ?? "#2b241a"}
                />
              </label>
              <label>
                Zoom do retrato
                <input
                  name="zoom"
                  type="range"
                  min={1}
                  max={4}
                  step={0.05}
                  defaultValue={token.appearance?.zoom ?? 1}
                />
              </label>
              <label>
                Recorte horizontal
                <input
                  name="x"
                  type="range"
                  min={-1}
                  max={1}
                  step={0.05}
                  defaultValue={token.appearance?.x ?? 0}
                />
              </label>
              <label>
                Recorte vertical
                <input
                  name="y"
                  type="range"
                  min={-1}
                  max={1}
                  step={0.05}
                  defaultValue={token.appearance?.y ?? 0}
                />
              </label>
              <button disabled={busy}>Aplicar aparência</button>
            </form>
          </details>
          <span>
            {token.name}
            <small>
              {actors.find((actor) => actor.id === token.actorId)?.type === "character"
                ? "Personagem"
                : "Token"}
            </small>
          </span>
          <button
            className="icon-button danger"
            aria-label={`Remover token ${token.name}`}
            onClick={() => void action(`/tokens/${token.id}`, undefined, "DELETE")}
            disabled={busy}
          >
            <Icon name="trash" size={15} />
          </button>
        </div>
      ))}
    </section>
  );
}

export function EncounterPreparationSection({
  actors,
  actorId,
  setActorId,
  quantity,
  setQuantity,
  requestId,
  renewRequest,
  busy,
  center,
  action,
}: {
  actors: Actor[];
  actorId: string;
  setActorId: (value: string) => void;
  quantity: number;
  setQuantity: (value: number) => void;
  requestId: string;
  renewRequest: () => void;
  busy: boolean;
  center: () => { x: number; y: number };
  action: SceneAction;
}) {
  return (
    <details>
      <summary>Preparar grupo de criaturas</summary>
      <p>
        Importe uma criatura da biblioteca para as fichas e escolha o modelo abaixo. Cada cópia terá
        seus próprios PV. Os tokens começam ocultos.
      </p>
      <label>
        Modelo
        <select
          value={actorId}
          onChange={(event) => {
            setActorId(event.target.value);
            renewRequest();
          }}
        >
          <option value="">Escolha uma criatura</option>
          {actors
            .filter((actor) => actor.type !== "character")
            .map((actor) => (
              <option key={actor.id} value={actor.id}>
                {actor.name}
              </option>
            ))}
        </select>
      </label>
      <label>
        Quantidade
        <input
          type="number"
          min={1}
          max={20}
          value={quantity}
          onChange={(event) => {
            setQuantity(Number(event.target.value));
            renewRequest();
          }}
        />
      </label>
      <button
        disabled={busy || !actorId || !Number.isInteger(quantity) || quantity < 1 || quantity > 20}
        onClick={() =>
          void action("/encounters", { actorId: Number(actorId), quantity, ...center(), requestId })
        }
      >
        Preparar tokens ocultos
      </button>
      <button onClick={renewRequest} disabled={busy}>
        Preparar outro grupo
      </button>
    </details>
  );
}

export function GeometrySection({
  state,
  busy,
  action,
}: {
  state: SceneState;
  busy: boolean;
  action: SceneAction;
}) {
  return (
    <>
      <section>
        <h3>
          <Icon name="map" size={17} /> Paredes e luzes
        </h3>
        <div>
          <button onClick={() => useSession.getState().setTool("wall")}>Desenhar paredes</button>
          <button onClick={() => useSession.getState().setTool("door")}>Desenhar portas</button>
          <button onClick={() => useSession.getState().setPanel("journal")}>Notas da cena</button>
        </div>
        <p>Desenhe paredes e portas no mapa. Remova trechos aqui para corrigir a preparação.</p>
        {(["walls", "lights"] as const).map((kind) =>
          state[kind].map((item, index) => (
            <div className="scene-object" key={item.id}>
              <span>
                {kind === "walls" ? "Parede" : "Luz"} {index + 1}
              </span>
              <button
                disabled={busy}
                onClick={() => void action(`/geometry/${kind}/${item.id}`, undefined, "DELETE")}
              >
                Remover
              </button>
            </div>
          )),
        )}
      </section>
      <section>
        <h3>
          <Icon name="door" size={17} />
          Portas <span>{state.doors.length}</span>
        </h3>
        {!state.doors.length && <p>Use a ferramenta Porta e arraste no mapa.</p>}
        {state.doors.map((door, index) => (
          <div className="scene-object" key={door.id}>
            <span>
              Porta {index + 1}
              <small>
                {door.open ? "Aberta" : "Fechada"} ·{" "}
                {door.state === "locked"
                  ? "trancada"
                  : door.state === "secret"
                    ? "secreta"
                    : "normal"}
              </small>
            </span>
            <select
              aria-label={`Tipo da porta ${index + 1}`}
              value={door.state ?? "normal"}
              disabled={busy}
              onChange={(event) => void action("/doors", { ...door, state: event.target.value })}
            >
              <option value="normal">Normal</option>
              <option value="locked">Trancada</option>
              <option value="secret">Secreta</option>
            </select>
            {door.state === "secret" && (
              <label>
                CD Percepção
                <input
                  type="number"
                  min={1}
                  max={40}
                  value={door.perceptionDc ?? ""}
                  placeholder="Não detectável"
                  onChange={(event) =>
                    void action("/doors", {
                      ...door,
                      perceptionDc: event.target.value ? Number(event.target.value) : null,
                    })
                  }
                />
              </label>
            )}
            <button
              disabled={busy || door.state === "locked"}
              onClick={() => void action(`/doors/${door.id}/toggle`)}
            >
              {door.open ? "Fechar" : "Abrir"}
            </button>
            <button
              disabled={busy}
              aria-label={`Remover porta ${index + 1}`}
              onClick={() => void action(`/geometry/doors/${door.id}`, undefined, "DELETE")}
            >
              Remover
            </button>
          </div>
        ))}
      </section>
      <section>
        <h3>
          <Icon name="fog" size={17} />
          Áreas reveladas <span>{state.fog.revealed.length}</span>
        </h3>
        <p>
          Use Revelar para mostrar partes do mapa. Luzes também revelam suas áreas aos jogadores.
        </p>
        {state.fog.revealed.map((rect, index) => (
          <div className="scene-object" key={index}>
            <span>
              Área {index + 1}
              <small>
                {Math.round(rect.w)} × {Math.round(rect.h)} px
              </small>
            </span>
            <button disabled={busy} onClick={() => void action("/fog", { ...rect, mode: "hide" })}>
              Ocultar
            </button>
          </div>
        ))}
      </section>
    </>
  );
}
