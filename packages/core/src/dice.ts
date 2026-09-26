import { z } from 'zod'

export type DiceRollResult = {
  formula: string
  total: number
  detail: string
  rolls: number[]
}

const DICE_RE = /^\s*(\d*)d(\d+)(?:(kh|kl)(\d*))?([+-]\d+)?\s*$/i

export type ParsedDiceFormula = {
  count: number
  sides: number
  modifier: number
  keepMode: 'kh' | 'kl' | null
  keepCount: number | null
}

/** Client-side syntax check / preview. Server is authoritative (App\Game\Dice\DiceRoller). */
export function parseDiceFormula(formula: string): ParsedDiceFormula | null {
  const m = formula.trim().match(DICE_RE)
  if (!m) return null
  const count = m[1] ? Number(m[1]) : 1
  const sides = Number(m[2])
  const keepMode = (m[3]?.toLowerCase() as 'kh' | 'kl' | undefined) ?? null
  const keepCount = keepMode ? (m[4] ? Number(m[4]) : 1) : null
  const modifier = m[5] ? Number(m[5]) : 0

  if (!Number.isFinite(count) || count < 1 || count > 100 || Math.abs(modifier) > 100000) return null
  if (!Number.isFinite(sides) || sides < 2 || sides > 1000) return null
  if (keepMode && (!keepCount || keepCount < 1 || keepCount > count)) return null

  return { count, sides, modifier, keepMode, keepCount }
}

export function isValidDiceFormula(formula: string): boolean {
  const normalized = formula.replace(/\s+/g, '')
  if (!/^[+-]?(?:\d*d\d+(?:(?:kh|kl)\d*)?|\d+)(?:[+-](?:\d*d\d+(?:(?:kh|kl)\d*)?|\d+))*$/i.test(normalized)) return false
  const terms = [...normalized.matchAll(/([+-]?)(\d*d\d+(?:(?:kh|kl)\d*)?|\d+)/gi)]
  if (!terms.length || terms.length > 20) return false
  let totalDice = 0
  for (const [, , term] of terms) {
    if (!/d/i.test(term)) {
      if (Number(term) > 100000) return false
      continue
    }
    const match = term.match(/^(\d*)d(\d+)(?:(kh|kl)(\d*))?$/i)
    if (!match) return false
    const count = match[1] ? Number(match[1]) : 1
    const sides = Number(match[2])
    const keep = match[3] ? (match[4] ? Number(match[4]) : 1) : null
    totalDice += count
    if (count < 1 || count > 100 || sides < 2 || sides > 1000 || (keep !== null && (keep < 1 || keep > count))) return false
    if (totalDice > 100) return false
  }
  return true
}

export const RollRecordSchema = z.object({
  id: z.string().uuid(), sceneId: z.number().int(), actorId: z.number().int().nullable(),
  userId: z.number().int(), recipientUserId: z.number().int().nullable(),
  context: z.enum(['custom','ability','skill','save','death-save','initiative','attack','damage','concentration']),
  edition: z.enum(['5e-2014','5e-2024']),
  step: z.string(), mode: z.enum(['normal','advantage','disadvantage']),
  visibility: z.enum(['public','gm','private']), label: z.string().nullable(),
  inputFormula: z.string(), formula: z.string(), modifier: z.number().int(), extraDice: z.string().nullable(),
  houseRules: z.array(z.string()), total: z.number(), detail: z.string(),
  rolls: z.array(z.number().int()), kept: z.array(z.number().int()), natural: z.number().int().nullable(),
  critical: z.boolean(), fumble: z.boolean(), createdAt: z.string(),
})
export type RollRecord = z.infer<typeof RollRecordSchema>

/** Monta `NdM(kh|kl)?±K` a partir de um modificador único (perícia, save, ataque...). */
export function buildModifierFormula(
  modifier: number,
  opts: { advantage?: boolean; disadvantage?: boolean; dice?: string } = {},
): string {
  const die = opts.dice ?? 'd20'
  const keep = opts.advantage && !opts.disadvantage ? '2' + die + 'kh1' : opts.disadvantage && !opts.advantage ? '2' + die + 'kl1' : '1' + die
  return modifier === 0 ? keep : `${keep}${modifier >= 0 ? '+' : ''}${modifier}`
}
