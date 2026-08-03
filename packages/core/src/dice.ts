export type DiceRollResult = {
  formula: string
  total: number
  detail: string
  rolls: number[]
}

const DICE_RE = /^\s*(\d*)d(\d+)([+-]\d+)?\s*$/i

/** Client-side syntax check / preview. Server is authoritative. */
export function parseDiceFormula(formula: string): { count: number; sides: number; modifier: number } | null {
  const m = formula.trim().match(DICE_RE)
  if (!m) return null
  const count = m[1] ? Number(m[1]) : 1
  const sides = Number(m[2])
  const modifier = m[3] ? Number(m[3]) : 0
  if (!Number.isFinite(count) || count < 1 || count > 100) return null
  if (!Number.isFinite(sides) || sides < 2 || sides > 1000) return null
  return { count, sides, modifier }
}

export function isValidDiceFormula(formula: string): boolean {
  return parseDiceFormula(formula) !== null
}
