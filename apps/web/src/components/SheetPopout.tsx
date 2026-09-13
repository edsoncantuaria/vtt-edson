import { useEffect } from "react";
import { useSession } from "../store/session";
import { useSceneSync } from "../lib/useSceneSync";
import "./TableView.css";
import { ActorSheet } from "./ActorSheet";
import { Brand, Icon } from "./Icon";

export function SheetPopout({ actorId }: { actorId: number }) {
  const { campaignName, actors, setSelectedActorId } = useSession();
  useSceneSync();
  useEffect(() => {
    setSelectedActorId(actorId);
  }, [actorId, setSelectedActorId]);
  const actor = actors.find((entry) => entry.id === actorId);
  return (
    <main className="sheet-popout">
      <header>
        <Brand />
        <span>{campaignName}</span>
        <button onClick={() => window.close()} aria-label="Fechar aba">
          <Icon name="close" />
        </button>
      </header>
      <section className="sheet-popout__body">
        {actor ? <ActorSheet /> : <p className="panel-hint">Carregando ficha…</p>}
      </section>
    </main>
  );
}
