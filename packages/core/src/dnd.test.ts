import { describe, expect, it } from 'vitest'
import { ActorActionSchema, ActorResourceSchema, ActorSystemSchema, abilityModifier, emptyActorSystem, formatModifier } from './dnd'

describe('typed damage and optional fixed reduction', () => {
  it('retains typed components on action and character schemas without breaking legacy actions', () => {
    const mixed = ActorActionSchema.parse({ id: 'flame-blade', name: 'Flame Blade', kind: 'attack',
      damageParts: [{ formula: '1d6+3', damageType: 'slashing' }, { formula: '2d6', damageType: 'fire' }] })
    expect(mixed.damageParts?.map(part => part.damageType)).toEqual(['slashing', 'fire'])
    expect(ActorActionSchema.parse({ id: 'legacy', name: 'Legacy', kind: 'spell', damageFormula: '8d6', damageType: 'fire' }).damageParts).toBeUndefined()
    const system = emptyActorSystem()
    system.damageReduction = 3
    system.actions = [mixed]
    expect(ActorSystemSchema.parse(system).damageReduction).toBe(3)
    expect(ActorActionSchema.safeParse({ ...mixed, damageParts: [{ formula: '1d6', damageType: 'invalid' }] }).success).toBe(false)
    expect(ActorSystemSchema.safeParse({ ...system, damageReduction: -1 }).success).toBe(false)
  })
})

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

describe('invariantes do estado de jogo', () => {
  it('contrato único aceita cura, alvos, alcance e ação sem rolagem, mas rejeita URLs inseguras', () => {
    const healing = {
      id: 'heal', name: 'Cura', kind: 'spell', healingFormula: '1d8+3',
      target: 'multiple', maxTargets: 3, rangeFeet: 30, economy: 'action', imageUrl: 'https://example.test/icon.png',
    }
    expect(ActorActionSchema.safeParse(healing).success).toBe(true)
    expect(ActorActionSchema.safeParse({ id: 'ward', name: 'Ward', kind: 'feature', effect: {
      name: 'Guard', target: 'self', trigger: 'on-use', duration: { unit: 'rounds', remaining: 1 }, modifiers: [], conditions: ['guarded'],
    } }).success).toBe(true)
    expect(ActorActionSchema.safeParse({ ...healing, rangeFeet: -1 }).success).toBe(false)
    expect(ActorActionSchema.safeParse({ ...healing, maxTargets: 51 }).success).toBe(false)
    expect(ActorActionSchema.safeParse({ ...healing, imageUrl: 'http://example.test/icon.png' }).success).toBe(false)
  })
  it('não permite PV negativos, acima do máximo nem PV temporários negativos', () => {
    const system = emptyActorSystem()
    expect(ActorSystemSchema.safeParse({ ...system, hp: { value: 11, max: 10, temp: 0 } }).success).toBe(false)
    expect(ActorSystemSchema.safeParse({ ...system, hp: { value: -1, max: 10, temp: 0 } }).success).toBe(false)
    expect(ActorSystemSchema.safeParse({ ...system, hp: { value: 5, max: 10, temp: -1 } }).success).toBe(false)
  })

  it('não permite gastar mais recursos ou slots do que existem', () => {
    expect(ActorResourceSchema.safeParse({ id: 'rage', name: 'Rage', max: 2, used: 3 }).success).toBe(false)
    const system = emptyActorSystem()
    expect(ActorSystemSchema.safeParse({ ...system, spells: { slots: { '1': { max: 2, used: 3 } }, known: [] } }).success).toBe(false)
  })

  it('não aceita quantidades fracionárias ou negativas no inventário', () => {
    const system = emptyActorSystem()
    const item = { id: 'potion', name: 'Potion' }
    expect(ActorSystemSchema.safeParse({ ...system, inventory: [{ ...item, quantity: 0 }] }).success).toBe(false)
    expect(ActorSystemSchema.safeParse({ ...system, inventory: [{ ...item, quantity: 1.5 }] }).success).toBe(false)
  })
})
