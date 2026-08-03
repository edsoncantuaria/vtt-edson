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
  imageUrl: z.string().nullable().optional(),
  size: z.number().default(1),
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
})

export const LightSchema = z.object({
  id: z.string(),
  x: z.number(),
  y: z.number(),
  radius: z.number().min(0),
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
  type: z.enum(['text', 'roll']),
  text: z.string().optional(),
  formula: z.string().optional(),
  total: z.number().optional(),
  detail: z.string().optional(),
  createdAt: z.string(),
})

export const SceneStateSchema = z.object({
  grid: GridSchema,
  backgroundUrl: z.string().nullable().optional(),
  tokens: z.array(TokenSchema),
  walls: z.array(WallSchema),
  doors: z.array(DoorSchema),
  lights: z.array(LightSchema),
  fog: z.object({ revealed: z.array(FogRectSchema) }),
  chat: z.array(ChatMessageSchema),
})

export type Grid = z.infer<typeof GridSchema>
export type Token = z.infer<typeof TokenSchema>
export type Wall = z.infer<typeof WallSchema>
export type Door = z.infer<typeof DoorSchema>
export type Light = z.infer<typeof LightSchema>
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
    fog: { revealed: [] },
    chat: [],
  }
}

export const EventNames = {
  SceneUpdated: 'SceneUpdated',
  ChatMessage: 'ChatMessage',
  PresenceUpdated: 'PresenceUpdated',
} as const

export type Role = 'gm' | 'player'
