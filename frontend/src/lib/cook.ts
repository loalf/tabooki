/** Durations mentioned in a step ("hornear 25 minutos", "simmer for 1 hour"), to start a timer from. */
export type StepTimer = { seconds: number; label: string }

const NUMBER = String.raw`(\d+(?:[.,]\d+)?|½|una?|one|a)`
const UNITS: [RegExp, number][] = [
  [/^(h|hrs?|hours?|horas?)$/i, 3600],
  [/^(min\.?|mins?|minutes?|minutos?)$/i, 60],
  [/^(s|seg\.?|secs?|seconds?|segundos?)$/i, 1],
]
const DURATION = new RegExp(
  String.raw`(?<![\w])${NUMBER}(?:\s*(?:-|–|a|to|o|or)\s*(\d+(?:[.,]\d+)?))?\s*(horas?|hours?|hrs?|h|minutos?|minutes?|mins?|min\.?|segundos?|seconds?|secs?|seg\.?|s)(?![\w])`,
  'gi',
)

function toNumber(text: string): number {
  const lower = text.toLowerCase()
  if (lower === '½') return 0.5
  if (['un', 'una', 'one', 'a'].includes(lower)) return 1
  return Number(lower.replace(',', '.'))
}

/** Each duration once, in order. A range ("10-12 min") starts at its lower end: check, then add. */
export function findTimers(step: string): StepTimer[] {
  const timers: StepTimer[] = []
  for (const match of step.matchAll(DURATION)) {
    const [label, amount, , unit] = match
    const factor = UNITS.find(([re]) => re.test(unit))?.[1]
    const value = toNumber(amount)
    if (!factor || !Number.isFinite(value) || value <= 0) continue
    // "a" / "s" alone are too ambiguous ("a s…"): need a digit when the unit is a bare letter.
    if (/^(a|una?|one)$/i.test(amount) && factor === 1) continue
    const seconds = Math.round(value * factor)
    if (seconds > 0 && seconds <= 24 * 3600 && !timers.some((t) => t.seconds === seconds)) {
      timers.push({ seconds, label: label.trim() })
    }
  }
  return timers
}

/** "12:05", "1:02:00". */
export function formatClock(totalSeconds: number): string {
  const s = Math.max(0, Math.ceil(totalSeconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

/**
 * An ingredient line for a different number of servings: the amount the recipe gives is
 * rewritten where it appears in the line ("300 g de lentejas" → "450 g de lentejas"). Lines
 * whose amount can't be found are left as they are (`scaled: false`), so the cook can adjust.
 */
export function scaleLine(rawText: string, quantity: number | null | undefined, factor: number, locale: string): { text: string; scaled: boolean } {
  if (factor === 1 || quantity == null || quantity <= 0) return { text: rawText, scaled: factor === 1 || quantity == null }
  const format = (n: number) => new Intl.NumberFormat(locale, { maximumFractionDigits: n < 10 ? 2 : 0 }).format(n)
  const target = quantity * factor
  const pattern = /(\d+)\s*\/\s*(\d+)|(\d+(?:[.,]\d+)?)|([½¼¾])/g
  const vulgar: Record<string, number> = { '½': 0.5, '¼': 0.25, '¾': 0.75 }
  for (const match of rawText.matchAll(pattern)) {
    const value = match[1] ? Number(match[1]) / Number(match[2]) : match[3] ? Number(match[3].replace(',', '.')) : vulgar[match[4]]
    if (Math.abs(value - quantity) < 1e-6) {
      const start = match.index ?? 0
      return { text: rawText.slice(0, start) + format(target) + rawText.slice(start + match[0].length), scaled: true }
    }
  }
  return { text: rawText, scaled: false }
}

const STOP_WORDS = new Set([
  'de', 'del', 'la', 'las', 'el', 'los', 'con', 'para', 'sin', 'una', 'unos', 'unas', 'y', 'o', 'al', 'a', 'en',
  'of', 'the', 'and', 'or', 'with', 'for', 'fresh', 'fresco', 'fresca', 'frescos', 'frescas', 'grande', 'grandes',
  'pequeño', 'pequeña', 'large', 'small', 'medium', 'mediano', 'mediana', 'picado', 'picada', 'chopped', 'gusto', 'taste',
])

function normalize(text: string): string {
  return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
}

/** "zanahorias" → "zanahori", so it matches "zanahoria" too. */
function stem(word: string): string {
  return word.length > 4 ? word.replace(/(es|s|a|o)$/, '') : word
}

/**
 * Which ingredients a step mentions ("Sofríe la cebolla y el pimiento" → cebolla, pimiento), by
 * their name, so cook mode can show just those next to the step. Returns indexes into the list.
 */
export function ingredientsInStep(step: string, ingredients: { name?: string | null; rawText: string }[]): number[] {
  const text = normalize(step)
  const found: number[] = []
  ingredients.forEach((ingredient, index) => {
    const name = ingredient.name?.trim() || ingredient.rawText.replace(/^[\d\s.,/½¼¾⅓⅔-]+/, '')
    const words = normalize(name)
      .split(/[^a-zñ]+/)
      .filter((w) => w.length >= 3 && !STOP_WORDS.has(w))
    if (words.some((w) => new RegExp(`\\b${stem(w)}`).test(text))) found.push(index)
  })
  return found
}
