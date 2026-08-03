/** Stub for future game-system plugins (dnd5e-srd52, etc.). */
export interface GameSystem {
  id: string
  name: string
  version: string
}

export const nullSystem: GameSystem = {
  id: 'none',
  name: 'Core (sem sistema)',
  version: '0.0.0',
}
