import type { SceneState } from "@vtt/core";
import type { FormEvent } from "react";
import { Icon } from "../Icon";

export type SceneAction = (suffix: string, data?: object, method?: string) => Promise<void>;

export function MapBackgroundSection({
  backgroundUrl,
  uploading,
  onUpload,
}: {
  backgroundUrl: string | null;
  uploading: boolean;
  onUpload: () => void;
}) {
  return (
    <section>
      <h3>
        <Icon name="map" size={17} />
        Mapa de fundo
      </h3>
      {backgroundUrl && <img className="scene-preview" src={backgroundUrl} alt="Mapa atual" />}
      <button onClick={onUpload} disabled={uploading}>
        <Icon name="upload" size={17} />
        {uploading ? "Enviando…" : backgroundUrl ? "Trocar mapa" : "Adicionar mapa"}
      </button>
      <p>JPG, PNG ou WebP, até 10 MB.</p>
      <a href="/watchtower-map-gemini.jpeg" download="torre-em-ruinas.jpeg">
        Baixar mapa inicial · Torre em ruínas
      </a>
    </section>
  );
}

export function VisionSection({
  vision,
  busy,
  action,
}: {
  vision: SceneState["vision"];
  busy: boolean;
  action: SceneAction;
}) {
  return (
    <section>
      <h3>
        <Icon name="light" size={17} /> Visão e iluminação
      </h3>
      <label className="check-label">
        <input
          type="checkbox"
          checked={vision.dynamic}
          onChange={(event) => void action("/vision", { dynamic: event.target.checked }, "PATCH")}
          disabled={busy}
        />{" "}
        Linha de visão dinâmica
      </label>
      <label className="check-label">
        <input
          type="checkbox"
          checked={vision.darkness}
          onChange={(event) =>
            void action(
              "/vision",
              { dynamic: vision.dynamic, darkness: event.target.checked },
              "PATCH",
            )
          }
          disabled={busy}
        />{" "}
        Escuridão ambiente
      </label>
      <label>
        Alcance visual em luz ambiente (ft)
        <input
          type="number"
          min={5}
          max={300}
          step={5}
          value={vision.normalVisionFeet}
          disabled={busy}
          onChange={(event) =>
            void action(
              "/vision",
              { dynamic: vision.dynamic, normalVisionFeet: Number(event.target.value) },
              "PATCH",
            )
          }
        />
      </label>
      <p>
        Portas fechadas e paredes bloqueiam a luz e a visão. Na escuridão, visão no escuro ou uma
        fonte de luz visível é necessária.
      </p>
    </section>
  );
}

export function AudioSection({
  audio,
  busy,
  action,
}: {
  audio: SceneState["audio"];
  busy: boolean;
  action: SceneAction;
}) {
  return (
    <section>
      <h3>
        <Icon name="music" size={17} /> Áudio ambiente
      </h3>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          const fields = new FormData(event.currentTarget);
          void action(
            "/audio",
            {
              url: fields.get("url") || null,
              volume: Number(fields.get("volume")) / 100,
              loop: fields.get("loop") === "on",
            },
            "PATCH",
          );
        }}
        key={[audio.url, audio.volume, audio.loop].join("-")}
      >
        <label>
          URL do áudio (HTTPS)
          <input
            type="url"
            name="url"
            defaultValue={audio.url ?? ""}
            placeholder="https://…/ambiente.mp3"
          />
        </label>
        <label>
          Volume{" "}
          <input
            type="range"
            name="volume"
            min="0"
            max="100"
            defaultValue={Math.round(audio.volume * 100)}
          />
        </label>
        <label className="check-label">
          <input type="checkbox" name="loop" defaultChecked={audio.loop} /> Repetir
        </label>
        <button disabled={busy}>Salvar ambiente</button>
      </form>
    </section>
  );
}

export function GridSection({
  grid,
  busy,
  action,
}: {
  grid: SceneState["grid"];
  busy: boolean;
  action: SceneAction;
}) {
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = new FormData(event.currentTarget);
    void action(
      "/grid",
      {
        size: Number(fields.get("size")),
        offsetX: Number(fields.get("offsetX")),
        offsetY: Number(fields.get("offsetY")),
        snap: fields.get("snap") === "on",
      },
      "PATCH",
    );
  }

  return (
    <section>
      <h3>
        <Icon name="grid" size={17} />
        Grade e movimento
      </h3>
      <form onSubmit={submit} key={[grid.size, grid.offsetX, grid.offsetY, grid.snap].join("-")}>
        <div className="form-columns">
          <label>
            Quadrado (px)
            <input type="number" name="size" min={8} max={500} defaultValue={grid.size} required />
          </label>
          <label>
            Deslocar X<input type="number" name="offsetX" defaultValue={grid.offsetX} required />
          </label>
          <label>
            Deslocar Y<input type="number" name="offsetY" defaultValue={grid.offsetY} required />
          </label>
        </div>
        <label className="check-label">
          <input type="checkbox" name="snap" defaultChecked={grid.snap} />
          Encaixar tokens na grade
        </label>
        <button disabled={busy}>Aplicar grade</button>
      </form>
    </section>
  );
}
