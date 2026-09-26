import { describe, expect, it } from 'vitest'
import { buildModifierFormula, isValidDiceFormula, parseDiceFormula } from './dice'
import { emptySceneState, SceneStateSchema } from './types'

describe('parseDiceFormula', () => {
  it('parses NdM±K', () => {
    expect(parseDiceFormula('2d6+3')).toEqual({ count: 2, sides: 6, modifier: 3, keepMode: null, keepCount: null })
    expect(parseDiceFormula('d20')).toEqual({ count: 1, sides: 20, modifier: 0, keepMode: null, keepCount: null })
    expect(parseDiceFormula('1d8-1')).toEqual({ count: 1, sides: 8, modifier: -1, keepMode: null, keepCount: null })
  })

  it('parses vantagem/desvantagem (kh/kl)', () => {
    expect(parseDiceFormula('2d20kh1+5')).toEqual({ count: 2, sides: 20, modifier: 5, keepMode: 'kh', keepCount: 1 })
    expect(parseDiceFormula('2d20kl1')).toEqual({ count: 2, sides: 20, modifier: 0, keepMode: 'kl', keepCount: 1 })
  })

  it('rejects invalid', () => {
    expect(isValidDiceFormula('abc')).toBe(false)
    expect(isValidDiceFormula('2d')).toBe(false)
    expect(isValidDiceFormula('2d20kh3')).toBe(false) // keep maior que a quantidade de dados
  })

  it('accepts the bounded additive grammar used by the PHP evaluator', () => {
    expect(isValidDiceFormula('2d20kh1+5+d4')).toBe(true)
    expect(isValidDiceFormula('d20 - 2 + 2d4')).toBe(true)
    expect(isValidDiceFormula('101d6')).toBe(false)
    expect(isValidDiceFormula('2d20kh3+d4')).toBe(false)
    expect(isValidDiceFormula('d20+100001')).toBe(false)
  })
})

describe('buildModifierFormula', () => {
  it('monta rolagem simples', () => {
    expect(buildModifierFormula(3)).toBe('1d20+3')
    expect(buildModifierFormula(-1)).toBe('1d20-1')
    expect(buildModifierFormula(0)).toBe('1d20')
  })

  it('monta vantagem e desvantagem', () => {
    expect(buildModifierFormula(4, { advantage: true })).toBe('2d20kh1+4')
    expect(buildModifierFormula(4, { disadvantage: true })).toBe('2d20kl1+4')
  })

  it('cancels simultaneous advantage and disadvantage', () => {
    expect(buildModifierFormula(3, { advantage: true, disadvantage: true })).toBe('1d20+3')
  })
})

describe('SceneStateSchema', () => {
  it('accepts empty scene', () => {
    expect(SceneStateSchema.parse(emptySceneState())).toBeTruthy()
  })
})
