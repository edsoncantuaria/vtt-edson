import { useState } from "react";
import { AssetLibrary } from "./campaign-tools/AssetLibrary";
import { MacroManager } from "./campaign-tools/MacroManager";
import { PermissionsManager } from "./campaign-tools/PermissionsManager";
import { SubsystemManager } from "./campaign-tools/SubsystemManager";

type Section = "assets" | "macros" | "systems" | "permissions";

export function CampaignToolsPanel({ center }: { center: () => { x: number; y: number } }) {
  const [section, setSection] = useState<Section>("assets");
  return (
    <div>
      <div className="segmented">
        <button aria-pressed={section === "assets"} onClick={() => setSection("assets")}>
          Assets
        </button>
        <button aria-pressed={section === "macros"} onClick={() => setSection("macros")}>
          Macros
        </button>
        <button aria-pressed={section === "systems"} onClick={() => setSection("systems")}>
          Sistemas
        </button>
        <button aria-pressed={section === "permissions"} onClick={() => setSection("permissions")}>
          Acesso
        </button>
      </div>
      {section === "assets" && <AssetLibrary center={center} />}
      {section === "macros" && <MacroManager />}
      {section === "systems" && <SubsystemManager />}
      {section === "permissions" && <PermissionsManager />}
    </div>
  );
}
