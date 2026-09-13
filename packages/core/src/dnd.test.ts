import { describe, expect, it } from 'vitest'
import { ActorSystemSchema, abilityModifier, emptyActorSystem, formatModifier } from './dnd'

describe('abilityModifier', () => {
  it('segue a tabela padrão 5e', () => {
    expect(abilityModifier(10)).toBe(0)
    expect(abilityModifier(11)).toBe(0)
    expect(abilityModifier(18)).toBe(4)
    expect(abilityModifier(8)).toBe(-1)
    expect(abilityModifier(9)).toBe(-1)
    expect(abilityModifier(1)).toBe(-5)
  })
})

describe('formatModifier', () => {
  it('formata com sinal', () => {
    expect(formatModifier(3)).toBe('+3')
    expect(formatModifier(0)).toBe('+0')
    expect(formatModifier(-2)).toBe('-2')
  })
})

describe('emptyActorSystem', () => {
  it('normaliza espaços de magia vazios de exportações antigas sem aceitar listas preenchidas', () => {
    const system = emptyActorSystem()
    expect(ActorSystemSchema.parse({ ...system, spells: { slots: [], known: [] } }).spells.slots).toEqual({})
    expect(ActorSystemSchema.safeParse({ ...system, spells: { slots: [{ max: 2, used: 0 }], known: [] } }).success).toBe(false)
  })
  it('produz uma ficha 5e válida contra o schema', () => {
    expect(ActorSystemSchema.parse(emptyActorSystem())).toBeTruthy()
  })

  it('tem as 18 perícias e as 6 salvaguardas', () => {
    const sys = emptyActorSystem()
    expect(Object.keys(sys.skills)).toHaveLength(18)
    expect(Object.keys(sys.saves)).toHaveLength(6)
  })
})
