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

  if (!Number.isFinite(count) || count < 1 || count > 100) return null
  if (!Number.isFinite(sides) || sides < 2 || sides > 1000) return null
  if (keepMode && (!keepCount || keepCount < 1 || keepCount > count)) return null

  return { count, sides, modifier, keepMode, keepCount }
}

export function isValidDiceFormula(formula: string): boolean {
  return parseDiceFormula(formula) !== null
}

/** Monta `NdM(kh|kl)?±K` a partir de um modificador único (perícia, save, ataque...). */
export function buildModifierFormula(
  modifier: number,
  opts: { advantage?: boolean; disadvantage?: boolean; dice?: string } = {},
): string {
  const die = opts.dice ?? 'd20'
  const keep = opts.advantage ? '2' + die + 'kh1' : opts.disadvantage ? '2' + die + 'kl1' : '1' + die
  return modifier === 0 ? keep : `${keep}${modifier >= 0 ? '+' : ''}${modifier}`
}
