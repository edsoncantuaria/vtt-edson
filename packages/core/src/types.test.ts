import { describe, expect, it } from 'vitest'
import { ChatMessageSchema, emptySceneState, SceneStateSchema } from './types'

describe('scene perception state', () => {
  it('supports V1 payloads without rewriting them and starts new scenes in V2', () => {
    const { schemaVersion: _version, ...legacy } = emptySceneState()
    const current = SceneStateSchema.parse(legacy)
    expect(current.schemaVersion).toBe(1)
    expect(emptySceneState().schemaVersion).toBe(2)
    expect(SceneStateSchema.parse({ ...current, schemaVersion: 2 }).schemaVersion).toBe(2)
    expect(SceneStateSchema.safeParse({ ...current, schemaVersion: 3 }).success).toBe(false)
  })
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

describe('audited guided spell messages', () => {
  it('keeps edition, slot, ritual, components and dart rolls on reconnect without requiring fields in legacy chat', () => {
    const original = {id:'spell-result',userId:1,userName:'Mage',type:'action',createdAt:'2026-09-26T18:00:00Z',
      actionKind:'spell',spellCast:{documentId:7,name:'Magic Missile',kind:'missiles',edition:'5e-2024',source:'XPHB',
        level:1,slotLevel:2,ritual:false,upcast:1,components:{v:true,s:true,m:null},
        missiles:[{tokenId:'orc',count:2},{tokenId:'goblin',count:2}]},
      rolls:[{id:'0e5b4561-2004-49af-9782-3d09b2b69bff',kind:'damage',formula:'1d4+1',total:3,detail:'2+1',
        critical:false,fumble:false,damageType:'force',targetActorId:23}]}
    const parsed = ChatMessageSchema.parse(original)
    expect(parsed.spellCast?.missiles?.[1].count).toBe(2)
    expect(parsed.rolls?.[0].targetActorId).toBe(23)
    expect(ChatMessageSchema.safeParse({id:'legacy',userId:1,userName:'Mage',type:'text',createdAt:'2026-09-26T18:00:00Z'}).success).toBe(true)
  })
})
