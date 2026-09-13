import { z } from 'zod'

export const GridSchema = z.object({
  size: z.number().min(8),
  offsetX: z.number(),
  offsetY: z.number(),
  snap: z.boolean(),
})

export const TokenSchema = z.object({
  id: z.string(),
  x: z.number(),
  y: z.number(),
  name: z.string(),
  ownerUserId: z.number().nullable(),
  hidden: z.boolean().optional(),
  stealthDc: z.number().int().min(1).max(40).nullable().optional(),
  detected: z.boolean().optional(),
  imageUrl: z.string().nullable().optional(),
  size: z.number().default(1),
  appearance: z.object({border:z.string(),background:z.string(),zoom:z.number(),x:z.number(),y:z.number()}).optional(),
  actorId: z.number().nullable().optional(),
})

export const WallSchema = z.object({
  id: z.string(),
  x1: z.number(),
  y1: z.number(),
  x2: z.number(),
  y2: z.number(),
})

export const DoorSchema = WallSchema.extend({
  open: z.boolean(),
  state: z.enum(['normal', 'locked', 'secret']).default('normal'),
  perceptionDc: z.number().int().min(1).max(40).nullable().optional(),
  detected: z.boolean().optional(),
})

export const AreaTemplateKindSchema = z.enum(['circle', 'cone', 'line', 'radius'])

export const AreaTemplateSchema = z.object({
  kind: AreaTemplateKindSchema,
  origin: z.object({ x: z.number(), y: z.number() }),
  end: z.object({ x: z.number(), y: z.number() }),
  distanceFeet: z.number().min(0),
  tokenIds: z.array(z.string()),
  actorIds: z.array(z.number()),
})

export const LightSchema = z.object({
  id: z.string(),
  x: z.number(),
  y: z.number(),
  radius: z.number().min(0),
})

export const DrawingSchema = z.object({
  id: z.string(),
  kind: z.enum(['freehand', 'rectangle', 'ellipse']).default('freehand'),
  points: z.array(z.object({ x: z.number(), y: z.number() })).min(2).max(500),
  stroke: z.string().default('#6fc3ff'),
  width: z.number().min(1).max(30).default(3),
  fill: z.string().nullable().optional(),
  hidden: z.boolean().default(false),
})

export const SceneLabelSchema = z.object({
  id: z.string(), x: z.number(), y: z.number(), text: z.string().max(500),
  fontSize: z.number().min(8).max(96).default(20), hidden: z.boolean().default(false),
})

export const TileSchema = z.object({
  id: z.string(), x: z.number(), y: z.number(), w: z.number().min(4), h: z.number().min(4),
  url: z.string(), assetId: z.number().nullable().optional(), opacity: z.number().min(0).max(1).default(1),
  rotation: z.number().default(0), hidden: z.boolean().default(false),
})

export const RegionSchema = z.object({
  id: z.string(), x: z.number(), y: z.number(), w: z.number().min(1), h: z.number().min(1),
  name: z.string().max(160), behavior: z.enum(['none','difficult-terrain','trigger','danger']).default('none'),
  note: z.string().max(1000).nullable().optional(), hidden: z.boolean().default(false),
})

export const PingSchema = z.object({
  id: z.string(), x: z.number(), y: z.number(), label: z.string().max(80).nullable().optional(),
  userName: z.string().max(120), createdAt: z.string(),
})

export const FogRectSchema = z.object({
  x: z.number(),
  y: z.number(),
  w: z.number(),
  h: z.number(),
})

export const ChatMessageSchema = z.object({
  id: z.string(),
  userId: z.number(),
  userName: z.string(),
  type: z.enum(['text', 'roll', 'action']),
  text: z.string().nullable().optional(),
  formula: z.string().optional(),
  total: z.number().optional(),
  detail: z.string().optional(),
  critical: z.boolean().optional(),
  fumble: z.boolean().optional(),
  label: z.string().nullable().optional(),
  houseRules: z.array(z.string()).optional(),
  rolls: z.array(z.object({kind:z.enum(['attack','damage']),formula:z.string(),total:z.number(),detail:z.string(),critical:z.boolean(),fumble:z.boolean()})).optional(),
  sourceActorId: z.number().optional(),
  damageType: z.string().nullable().optional(),
  save: z.object({ability:z.enum(['str','dex','con','int','wis','cha']),dc:z.number(),effect:z.enum(['half','none'])}).nullable().optional(),
  actionKind: z.enum(['attack', 'spell']).optional(),
  effectUrl: z.string().url().nullable().optional(),
  createdAt: z.string(),
})

export const SceneStateSchema = z.object({
  preparation: z.object({entryId:z.number(),chapter:z.string(),source:z.string(),sourceHash:z.string(),reviewed:z.boolean()}).optional(),
  grid: GridSchema,
  backgroundUrl: z.string().nullable().optional(),
  tokens: z.array(TokenSchema),
  walls: z.array(WallSchema),
  doors: z.array(DoorSchema),
  lights: z.array(LightSchema),
  drawings: z.array(DrawingSchema).default([]),
  labels: z.array(SceneLabelSchema).default([]),
  tiles: z.array(TileSchema).default([]),
  regions: z.array(RegionSchema).default([]),
  pings: z.array(PingSchema).default([]),
  vision: z.object({
    dynamic: z.boolean().default(false),
    darkness: z.boolean().default(false),
    normalVisionFeet: z.number().int().min(5).max(300).default(60),
  }).default({ dynamic: false, darkness: false, normalVisionFeet: 60 }),
  audio: z.object({
    url: z.string().url().nullable().default(null),
    volume: z.number().min(0).max(1).default(0.5),
    loop: z.boolean().default(true),
  }).default({ url: null, volume: 0.5, loop: true }),
  fog: z.object({ revealed: z.array(FogRectSchema) }),
  chat: z.array(ChatMessageSchema),
})

export type Grid = z.infer<typeof GridSchema>
export type Token = z.infer<typeof TokenSchema>
export type Wall = z.infer<typeof WallSchema>
export type Door = z.infer<typeof DoorSchema>
export type AreaTemplateKind = z.infer<typeof AreaTemplateKindSchema>
export type AreaTemplate = z.infer<typeof AreaTemplateSchema>
export type Light = z.infer<typeof LightSchema>
export type Drawing = z.infer<typeof DrawingSchema>
export type SceneLabel = z.infer<typeof SceneLabelSchema>
export type Tile = z.infer<typeof TileSchema>
export type Region = z.infer<typeof RegionSchema>
export type Ping = z.infer<typeof PingSchema>
export type FogRect = z.infer<typeof FogRectSchema>
export type ChatMessage = z.infer<typeof ChatMessageSchema>
export type SceneState = z.infer<typeof SceneStateSchema>

export function emptySceneState(): SceneState {
  return {
    grid: { size: 70, offsetX: 0, offsetY: 0, snap: true },
    backgroundUrl: null,
    tokens: [],
    walls: [],
    doors: [],
    lights: [],
    drawings: [],
    labels: [],
    tiles: [],
    regions: [],
    pings: [],
    vision: { dynamic: false, darkness: false, normalVisionFeet: 60 },
    audio: { url: null, volume: 0.5, loop: true },
    fog: { revealed: [] },
    chat: [],
  }
}

export const EventNames = {
  SceneUpdated: 'SceneUpdated',
  ChatMessage: 'ChatMessage',
  PresenceUpdated: 'PresenceUpdated',
} as const

export type Role = 'gm' | 'assistant' | 'player' | 'observer'

export function isManagerRole(role: Role | null | undefined): boolean {
  return role === 'gm' || role === 'assistant'
}
