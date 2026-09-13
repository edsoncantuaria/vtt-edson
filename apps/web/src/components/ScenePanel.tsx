import type { Role, SceneState } from "@vtt/core";
import { useState, type FormEvent } from "react";
import { api } from "../lib/api";
import { campaignBackupFilename, downloadCampaignBackup } from "../lib/campaignBackup";
import { updateScene } from "../lib/scene";
import { useSession } from "../store/session";
import { CampaignLibrary } from "./CampaignLibrary";
import { EncounterBuilder } from "./EncounterBuilder";
import { HomebrewManager } from "./HomebrewManager";
import { HouseRulesPanel } from "./HouseRulesPanel";
import { Icon } from "./Icon";
import { LootManager } from "./LootManager";
import { MapPreparation } from "./MapPreparation";
import { PlaylistManager } from "./PlaylistManager";
import { RollTableManager } from "./RollTableManager";
import {
  AudioSection,
  GridSection,
  MapBackgroundSection,
  VisionSection,
} from "./scene-panel/SceneEnvironmentSections";
import {
  EncounterPreparationSection,
  GeometrySection,
  TokensSection,
} from "./scene-panel/SceneObjectSections";

type SceneChoice = {
  id: number;
  published?: boolean;
  name: string;
  role: Role;
  state: SceneState;
  backgroundUrl: string | null;
};

export function ScenePanel({
  uploading,
  onUpload,
  center,
  scenes,
  onScenesChange,
  onSceneChange,
}: {
  uploading: boolean;
  onUpload: () => void;
  center: () => { x: number; y: number };
  scenes: SceneChoice[];
  onScenesChange: (scenes: SceneChoice[]) => void;
  onSceneChange: (id: number) => void;
}) {
  const { sceneId, state, backgroundUrl, actors, setError, campaignId, campaignName } =
    useSession();
  const [busy, setBusy] = useState(false);
  const [actorId, setActorId] = useState("");
  const [quantity, setQuantity] = useState(1);
  const [encounterRequest, setEncounterRequest] = useState(() => crypto.randomUUID());
  const [newScene, setNewScene] = useState("");
  const [lootSeed, setLootSeed] = useState<{
    rollTableRollId?: number;
    name: string;
    data: Record<string, unknown>;
  } | null>(null);

  async function exportCampaign() {
    if (!campaignId || busy) return;
    setBusy(true);
    try {
      const archive = await api<unknown>(`/campaigns/${campaignId}/export`);
      downloadCampaignBackup(archive, campaignBackupFilename(campaignName, campaignId));
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível exportar a campanha.");
    } finally {
      setBusy(false);
    }
  }

  async function action(suffix: string, data?: object, method = "POST") {
    if (!sceneId || busy) return;
    setBusy(true);
    try {
      await updateScene(sceneId, suffix, data, method);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível atualizar a cena.");
    } finally {
      setBusy(false);
    }
  }

  async function publishScene(id: number, published: boolean) {
    setBusy(true);
    try {
      const result = await api<{ scene: SceneChoice }>(`/scenes/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ published }),
      });
      onScenesChange(scenes.map((scene) => (scene.id === id ? result.scene : scene)));
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível publicar a cena.");
    } finally {
      setBusy(false);
    }
  }

  async function createScene(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!campaignId || !newScene.trim() || busy) return;
    setBusy(true);
    try {
      const result = await api<{ scene: SceneChoice }>(`/campaigns/${campaignId}/scenes`, {
        method: "POST",
        body: JSON.stringify({ name: newScene.trim() }),
      });
      onScenesChange([...scenes, result.scene]);
      setNewScene("");
      onSceneChange(result.scene.id);
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível criar a cena.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="scene-panel">
      <details>
        <summary>Backup completo da campanha</summary>
        <p>
          O JSON inclui fichas, cenas, diário, combates, histórico reversível e uploads locais de
          mapas/retratos até o limite do backup. Ele pode ser restaurado pela biblioteca de mesas.
        </p>
        <button disabled={busy} onClick={() => void exportCampaign()}>
          Baixar backup privado em JSON
        </button>
      </details>
      <CampaignLibrary key={campaignId} />
      <details>
        <summary>Regras da casa</summary>
        <HouseRulesPanel key={campaignId} />
      </details>
      <details>
        <summary>Homebrew versionado</summary>
        <HomebrewManager />
      </details>
      <details>
        <summary>Construtor de encontros</summary>
        <EncounterBuilder />
      </details>
      <details>
        <summary>Tabelas e tesouro</summary>
        <RollTableManager onLootResult={setLootSeed} />
        <LootManager seed={lootSeed} />
      </details>

      <section>
        <h3>
          <Icon name="map" size={17} /> Cenas <span>{scenes.length}</span>
        </h3>
        {scenes.map((scene) => (
          <div key={scene.id}>
            <button
              className="scene-object"
              disabled={scene.id === sceneId}
              onClick={() => onSceneChange(scene.id)}
            >
              {scene.name}
              {scene.published === false ? " · preparação" : " · publicada"}
              {scene.id === sceneId ? " · visualizando" : ""}
            </button>
            <button
              disabled={busy}
              onClick={() => void publishScene(scene.id, scene.published === false)}
            >
              {scene.published === false ? "Publicar para o grupo" : "Voltar à preparação"}
            </button>
          </div>
        ))}
        <form onSubmit={createScene}>
          <label>
            Nova cena
            <input
              value={newScene}
              maxLength={120}
              onChange={(event) => setNewScene(event.target.value)}
              placeholder="Ex.: Cripta inundada"
            />
          </label>
          <button disabled={!newScene.trim() || busy}>
            <Icon name="plus" size={15} /> Criar cena
          </button>
        </form>
        <PlaylistManager />
      </section>

      {state.preparation && <MapPreparation key={sceneId} />}
      <MapBackgroundSection
        backgroundUrl={backgroundUrl}
        uploading={uploading}
        onUpload={onUpload}
      />
      <VisionSection vision={state.vision} busy={busy} action={action} />
      <AudioSection audio={state.audio} busy={busy} action={action} />
      <GridSection grid={state.grid} busy={busy} action={action} />
      <TokensSection
        tokens={state.tokens}
        actors={actors}
        actorId={actorId}
        setActorId={setActorId}
        busy={busy}
        center={center}
        action={action}
      />
      <EncounterPreparationSection
        actors={actors}
        actorId={actorId}
        setActorId={setActorId}
        quantity={quantity}
        setQuantity={setQuantity}
        requestId={encounterRequest}
        renewRequest={() => setEncounterRequest(crypto.randomUUID())}
        busy={busy}
        center={center}
        action={action}
      />
      <GeometrySection state={state} busy={busy} action={action} />
    </div>
  );
}
