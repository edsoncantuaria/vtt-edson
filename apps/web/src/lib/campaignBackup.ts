export type CampaignArchive = Record<string, unknown> & {
  format: "vtt-edson-campaign";
  version: number;
};

export function parseCampaignBackup(text: string): CampaignArchive {
  let value: unknown;
  try {
    value = JSON.parse(text);
  } catch {
    throw new Error("O arquivo não contém um JSON válido.");
  }
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error("O arquivo não é um backup de campanha válido.");
  }
  const archive = value as Record<string, unknown>;
  if (archive.format !== "vtt-edson-campaign") {
    throw new Error("O arquivo não foi reconhecido como backup do VTT Edson.");
  }
  if (archive.version !== 1 && archive.version !== 2) {
    throw new Error("A versão deste backup não é compatível com esta instalação.");
  }
  return archive as CampaignArchive;
}

export function campaignBackupFilename(name: string, id: number): string {
  const slug = name
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("pt-BR")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
  return `campanha-${slug || id}-${id}.json`;
}

export function downloadCampaignBackup(archive: unknown, filename: string): void {
  const url = URL.createObjectURL(
    new Blob([JSON.stringify(archive, null, 2)], { type: "application/json" }),
  );
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
