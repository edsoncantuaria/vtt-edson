import { describe, expect, it } from 'vitest'
import { SceneStateSchema } from './types'

describe('scene perception state', () => {
  it('defaults legacy scene vision without weakening new perception fields', () => {
    const state = SceneStateSchema.parse({
      grid: { size: 70, offsetX: 0, offsetY: 0, snap: true },
      tokens: [{ id: 'hidden', x: 10, y: 10, name: 'Hidden', ownerUserId: null, hidden: true, stealthDc: 15, detected: true, size: 1 }],
      walls: [],
      doors: [{ id: 'secret', x1: 0, y1: 0, x2: 10, y2: 0, open: false, state: 'secret', perceptionDc: 14, detected: true }],
      lights: [],
      vision: { dynamic: true },
      audio: { url: null, volume: 0.5, loop: true },
      fog: { revealed: [] },
      chat: [],
    })
    expect(state.vision).toEqual({ dynamic: true, darkness: false, normalVisionFeet: 60 })
    expect(state.tokens[0].stealthDc).toBe(15)
    expect(state.tokens[0].detected).toBe(true)
    expect(state.doors[0].perceptionDc).toBe(14)
  })
})
