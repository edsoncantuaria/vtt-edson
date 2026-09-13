import { z } from 'zod'

/** Perícias 5e SRD → habilidade associada. Espelha App\Support\Dnd\Skills no backend. */
export const SKILL_ABILITY = {
  acrobatics: 'dex',
  animalHandling: 'wis',
  arcana: 'int',
  athletics: 'str',
  deception: 'cha',
  history: 'int',
  insight: 'wis',
  intimidation: 'cha',
  investigation: 'int',
  medicine: 'wis',
  nature: 'int',
  perception: 'wis',
  performance: 'cha',
  persuasion: 'cha',
  religion: 'int',
  sleightOfHand: 'dex',
  stealth: 'dex',
  survival: 'wis',
} as const

export const SKILL_LABELS: Record<keyof typeof SKILL_ABILITY, string> = {
  acrobatics: 'Acrobacia',
  animalHandling: 'Adestrar Animais',
  arcana: 'Arcanismo',
  athletics: 'Atletismo',
  deception: 'Enganação',
  history: 'História',
  insight: 'Intuição',
  intimidation: 'Intimidação',
  investigation: 'Investigação',
  medicine: 'Medicina',
  nature: 'Natureza',
  perception: 'Percepção',
  performance: 'Atuação',
  persuasion: 'Persuasão',
  religion: 'Religião',
  sleightOfHand: 'Prestidigitação',
  stealth: 'Furtividade',
  survival: 'Sobrevivência',
}

export const ABILITY_LABELS: Record<Ability, string> = {
  str: 'Força',
  dex: 'Destreza',
  con: 'Constituição',
  int: 'Inteligência',
  wis: 'Sabedoria',
  cha: 'Carisma',
}

export type Ability = 'str' | 'dex' | 'con' | 'int' | 'wis' | 'cha'
export type SkillKey = keyof typeof SKILL_ABILITY

export function abilityModifier(score: number): number {
  return Math.floor((score - 10) / 2)
}

export function formatModifier(n: number): string {
  return n >= 0 ? `+${n}` : `${n}`
}

const AbilitySchema = z.object({ score: z.number().min(1).max(30) })
const SkillSchema = z.object({ proficient: z.boolean(), expertise: z.boolean() })
const SaveSchema = z.object({ proficient: z.boolean(), bonus: z.number().int().min(-30).max(50).optional() })

export const DAMAGE_LABELS = {acid:'Ácido',bludgeoning:'Contundente',cold:'Frio',fire:'Fogo',force:'Força',lightning:'Elétrico',necrotic:'Necrótico',piercing:'Perfurante',poison:'Veneno',psychic:'Psíquico',radiant:'Radiante',slashing:'Cortante',thunder:'Trovejante'} as const
export const DamageTypeSchema = z.enum(['acid','bludgeoning','cold','fire','force','lightning','necrotic','piercing','poison','psychic','radiant','slashing','thunder'])

export const InventoryItemSchema = z.object({
  id: z.string(),
  documentId: z.number().int().positive().optional(),
  slug: z.string().nullable().optional(),
  name: z.string(),
  quantity: z.number().default(1),
  weight: z.number().optional(),
  equipped: z.boolean().default(false),
  description: z.string().optional(),
  /** Quando presentes, a ficha mostra botões de rolagem de ataque/dano pro item. */
  attackBonus: z.number().optional(),
  damage: z.string().optional(),
})

export const KnownSpellSchema = z.object({
  id: z.string(),
  documentId: z.number().int().positive().optional(),
  slug: z.string().nullable().optional(),
  name: z.string(),
  level: z.number(),
  prepared: z.boolean().default(false),
  description: z.string().optional(),
})

export const ActorActionSchema = z.object({
  id: z.string(),
  name: z.string().min(1).max(120),
  kind: z.enum(['attack', 'spell']),
  attackFormula: z.string().optional(),
  attackAbility: z.enum(['str','dex','con','int','wis','cha','spellcasting','weapon']).optional(),
  attackBonus: z.number().int().min(-30).max(30).optional(),
  damageFormula: z.string().optional(),
  damageAbility: z.enum(['str','dex','con','int','wis','cha','spellcasting','weapon']).optional(),
  damageBonus: z.number().int().min(-30).max(30).optional(),
  spellSlotLevel: z.number().int().min(1).max(9).optional(),
  resourceId: z.string().max(80).optional(),
  resourceCost: z.number().int().min(1).max(1000).optional(),
  documentId: z.number().int().positive().optional(),
  chargeCost: z.number().int().min(1).max(1000).optional(),
  saveAbility: z.enum(['str', 'dex', 'con', 'int', 'wis', 'cha']).optional(),
  saveDc: z.number().int().min(1).max(99).optional(),
  saveEffect: z.enum(['half','none']).optional(),
  damageType: DamageTypeSchema.optional(),
  concentration: z.boolean().optional(),
  effect: z.object({
    name: z.string().min(1).max(160),
    target: z.enum(['self', 'targets']).default('targets'),
    trigger: z.enum(['on-use','on-hit','on-failed-save']).default('on-use'),
    duration: z.object({
      unit: z.enum(['rounds','minutes','hours','until-short-rest','until-long-rest','permanent']),
      remaining: z.number().int().min(0).max(100000).optional(),
    }),
    modifiers: z.array(z.object({ path: z.string(), mode: z.enum(['add','multiply','override']), value: z.union([z.number(), z.string().regex(/^\d*d\d+(?:[+-]\d+)?$/i)]) })).max(30).default([]),
    conditions: z.array(z.string().max(120)).max(20).default([]),
  }).optional(),
  economy: z.enum(['action','bonus','reaction','other']).optional(),
  description: z.string().optional(),
  effectUrl: z.string().url().refine((url) => url.startsWith('https://'), 'Use uma URL HTTPS para o efeito.').optional(),
})

export const ActorResourceSchema = z.object({
  id: z.string().min(1).max(80),
  name: z.string().min(1).max(120),
  max: z.number().int().min(0).max(100000),
  used: z.number().int().min(0).max(100000),
  reset: z.enum(['short','long','manual']).default('manual'),
})

export const FeatureSchema = z.object({
  id: z.string(),
  documentId: z.number().int().positive().optional(),
  name: z.string(),
  source: z.string().optional(),
  level: z.number().int().min(1).max(20).optional(),
  description: z.string().optional(),
})

export const ActorDocumentSchema = z.object({
  id: z.number(),
  actor_id: z.number(),
  catalog_entry_id: z.number().nullable().optional(),
  kind: z.enum(['item', 'spell', 'feature']),
  name: z.string(),
  slug: z.string().nullable().optional(),
  source: z.string().nullable().optional(),
  data: z.record(z.string(), z.unknown()).default({}),
  overrides: z.record(z.string(), z.unknown()).default({}),
  quantity: z.number().int().min(1).default(1),
  equipped: z.boolean().default(false),
  prepared: z.boolean().default(false),
  attuned: z.boolean().default(false),
  charges: z.object({ value: z.number().int().min(0), max: z.number().int().min(0), reset: z.enum(['short','long','dawn','manual']), recoveryFormula: z.string().regex(/^\d*d\d+(?:[+-]\d+)?$/i).optional() }).nullable().optional(),
  sort: z.number().int().min(0).default(0),
})

export const ActiveEffectSchema = z.object({
  id: z.number(),
  actor_id: z.number(),
  source_document_id: z.number().nullable().optional(),
  name: z.string(),
  duration: z.object({
    unit: z.enum(['rounds','minutes','hours','until-short-rest','until-long-rest','permanent']),
    remaining: z.number().int().min(0).nullable().optional(),
  }),
  modifiers: z.array(z.object({ path: z.string(), mode: z.enum(['add','multiply','override']), value: z.union([z.number(), z.string().regex(/^\d*d\d+(?:[+-]\d+)?$/i)]) })).default([]),
  conditions: z.array(z.string()).default([]),
  metadata: z.record(z.string(), z.unknown()).default({}),
  active: z.boolean().default(true),
})

export const ClassProgressionSchema = z.object({
  classId: z.number().int().positive().optional(),
  name: z.string().min(1),
  source: z.string().optional(),
  level: z.number().int().min(1).max(20),
  hitDie: z.number().int().min(4).max(12).optional(),
})

export const SubclassProgressionSchema = z.object({
  subclassId: z.number().int().positive().optional(),
  name: z.string().min(1),
  source: z.string().optional(),
  className: z.string().min(1),
  classSource: z.string().optional(),
})

export const ActorSystemSchema = z.object({
  tokenImageUrl: z.string().optional(),
  concentration: z.object({id:z.string(),name:z.string()}).nullable().optional(),
  damageTraits: z.object({resist:z.array(DamageTypeSchema).default([]),immune:z.array(DamageTypeSchema).default([]),vulnerable:z.array(DamageTypeSchema).default([])}).optional(),
  preparation: z.object({classId:z.number(),source:z.string(),edition:z.string(),tasks:z.array(z.object({text:z.string(),done:z.boolean()}))}).optional(),
  progression: z.object({
    classes: z.array(ClassProgressionSchema).min(1),
    subclasses: z.array(SubclassProgressionSchema).max(20).optional(),
    // Legacy single-subclass field kept for imported/older actor payloads.
    subclass: SubclassProgressionSchema.nullable().optional(),
  }).nullable().optional(),
  statBlock: z.record(z.string(), z.unknown()).optional(),
  abilities: z.object({
    str: AbilitySchema,
    dex: AbilitySchema,
    con: AbilitySchema,
    int: AbilitySchema,
    wis: AbilitySchema,
    cha: AbilitySchema,
  }),
  conditions: z.array(z.string()).default([]),
  inspiration: z.boolean().default(false),
  hitDice: z.object({die:z.number().int().min(4).max(12).default(8), total:z.number().int().min(0).default(1), used:z.number().int().min(0).default(0)}).default({die:8,total:1,used:0}),
  deathSaves: z.object({success:z.number().int().min(0).max(3),failure:z.number().int().min(0).max(3)}).default({success:0,failure:0}),
  proficiencies: z.string().default(''),
  spellcastingAbility: z.enum(['str','dex','con','int','wis','cha']).default('int'),
  proficiencyBonus: z.number().default(2),
  hp: z.object({ value: z.number(), max: z.number(), temp: z.number().default(0) }),
  ac: z.number(),
  speed: z.number(),
  skills: z.record(z.string(), SkillSchema),
  saves: z.record(z.string(), SaveSchema),
  senses: z.object({ darkvision: z.number().default(0) }),
  languages: z.array(z.string()).default([]),
  inventory: z.array(InventoryItemSchema).default([]),
  spells: z.object({
    slots: z.preprocess(
      (value) => Array.isArray(value) && value.length === 0 ? {} : value,
      z.record(z.string(), z.object({ max: z.number(), used: z.number() })).default({}),
    ),
    known: z.array(KnownSpellSchema).default([]),
  }),
  actions: z.array(ActorActionSchema).default([]),
  resources: z.array(ActorResourceSchema).default([]),
  features: z.array(FeatureSchema).default([]),
  bio: z.object({
    class: z.string().default(''),
    level: z.number().default(1),
    race: z.string().default(''),
    background: z.string().default(''),
    alignment: z.string().default(''),
    notes: z.string().default(''),
  }),
  currency: z.object({
    cp: z.number().default(0),
    sp: z.number().default(0),
    ep: z.number().default(0),
    gp: z.number().default(0),
    pp: z.number().default(0),
  }),
})

export const ActorSchema = z.object({
  revision: z.number().int().optional(),
  id: z.number(),
  campaignId: z.number(),
  ownerUserId: z.number().nullable(),
  type: z.enum(['character', 'npc', 'monster']),
  name: z.string(),
  shared: z.boolean().optional(),
  imgPath: z.string().nullable().optional(),
  imgUrl: z.string().nullable().optional(),
  system: ActorSystemSchema,
  documents: z.array(ActorDocumentSchema).default([]),
  activeEffects: z.array(ActiveEffectSchema).default([]),
})

export type ActorSystem = z.infer<typeof ActorSystemSchema>
export type Actor = z.infer<typeof ActorSchema>
export type InventoryItem = z.infer<typeof InventoryItemSchema>
export type KnownSpell = z.infer<typeof KnownSpellSchema>
export type ActorAction = z.infer<typeof ActorActionSchema>
export type ActorResource = z.infer<typeof ActorResourceSchema>
export type ClassProgression = z.infer<typeof ClassProgressionSchema>
export type SubclassProgression = z.infer<typeof SubclassProgressionSchema>
export type Feature = z.infer<typeof FeatureSchema>
export type ActorDocument = z.infer<typeof ActorDocumentSchema>
export type ActiveEffect = z.infer<typeof ActiveEffectSchema>

/** Ficha "portátil" pra import/export — mesmo shape que ActorController::export devolve. */
export const CharacterImportSchema = z.object({
  type: z.enum(['character', 'npc', 'monster']),
  name: z.string(),
  system: ActorSystemSchema,
})
export type CharacterImport = z.infer<typeof CharacterImportSchema>

export const CombatParticipantSchema = z.object({
  id: z.number(),
  combatId: z.number(),
  actorId: z.number().nullable(),
  tokenId: z.string().nullable(),
  name: z.string(),
  imgUrl: z.string().nullable(),
  initiative: z.number().nullable(),
  hidden: z.boolean(),
  sort: z.number(),
})

export const CombatSchema = z.object({
  id: z.number(),
  sceneId: z.number(),
  round: z.number(),
  turn: z.number(),
  isActive: z.boolean(),
  participants: z.array(CombatParticipantSchema),
})

export type Combat = z.infer<typeof CombatSchema>
export type Combatant = z.infer<typeof CombatParticipantSchema>

export function emptyActorSystem(): ActorSystem {
  const abilities = { str: { score: 10 }, dex: { score: 10 }, con: { score: 10 }, int: { score: 10 }, wis: { score: 10 }, cha: { score: 10 } }
  const skills = Object.fromEntries(
    (Object.keys(SKILL_ABILITY) as SkillKey[]).map((k) => [k, { proficient: false, expertise: false }]),
  )
  const saves = Object.fromEntries((['str', 'dex', 'con', 'int', 'wis', 'cha'] as Ability[]).map((k) => [k, { proficient: false }]))

  return {
    abilities,
    conditions:[], inspiration:false, hitDice:{die:8,total:1,used:0}, deathSaves:{success:0,failure:0}, proficiencies:'', spellcastingAbility:'int',
    proficiencyBonus: 2,
    hp: { value: 10, max: 10, temp: 0 },
    ac: 10,
    speed: 30,
    skills,
    saves,
    senses: { darkvision: 0 },
    languages: ['Comum'],
    inventory: [],
    spells: { slots: {}, known: [] },
    actions: [],
    resources: [],
    features: [],
    bio: { class: '', level: 1, race: '', background: '', alignment: '', notes: '' },
    currency: { cp: 0, sp: 0, ep: 0, gp: 0, pp: 0 },
  }
}
