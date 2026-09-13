import { describe, expect, it } from "vitest";
import { campaignBackupFilename, parseCampaignBackup } from "./campaignBackup";

describe("campaign backup helpers", () => {
  it("accepts supported campaign archives", () => {
    const archive = parseCampaignBackup(
      JSON.stringify({
        format: "vtt-edson-campaign",
        version: 3,
        campaign: { name: "Teste" },
      }),
    );
    expect(archive.version).toBe(3);
  });

  it("returns friendly errors for malformed or incompatible files", () => {
    expect(() => parseCampaignBackup("{nope")).toThrow("O arquivo não contém um JSON válido.");
    expect(() => parseCampaignBackup(JSON.stringify({ format: "other", version: 2 }))).toThrow(
      "O arquivo não foi reconhecido como backup do VTT Edson.",
    );
    expect(() =>
      parseCampaignBackup(JSON.stringify({ format: "vtt-edson-campaign", version: 99 })),
    ).toThrow("A versão deste backup não é compatível com esta instalação.");
  });

  it("builds a portable download filename", () => {
    expect(campaignBackupFilename("Crônicas de Valdória!", 42)).toBe(
      "campanha-cronicas-de-valdoria-42.json",
    );
  });
});
