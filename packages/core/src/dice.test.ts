import { describe, expect, it } from 'vitest'
import { isValidDiceFormula, parseDiceFormula } from './dice'
import { emptySceneState, SceneStateSchema } from './types'

describe('parseDiceFormula', () => {
  it('parses NdM±K', () => {
    expect(parseDiceFormula('2d6+3')).toEqual({ count: 2, sides: 6, modifier: 3 })
    expect(parseDiceFormula('d20')).toEqual({ count: 1, sides: 20, modifier: 0 })
    expect(parseDiceFormula('1d8-1')).toEqual({ count: 1, sides: 8, modifier: -1 })
  })

  it('rejects invalid', () => {
    expect(isValidDiceFormula('abc')).toBe(false)
    expect(isValidDiceFormula('2d')).toBe(false)
  })
})

describe('SceneStateSchema', () => {
  it('accepts empty scene', () => {
    expect(SceneStateSchema.parse(emptySceneState())).toBeTruthy()
  })
})
