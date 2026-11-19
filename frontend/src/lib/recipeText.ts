import type { Ingredient, IngredientInput } from '@/api/library'

/**
 * Ingredients are edited as plain text: one per line, and a line ending in ":" starts a group
 * ("Para la salsa:"). Lines that didn't change keep their structured fields (quantity, unit…),
 * so editing an extracted recipe doesn't throw that work away.
 */
export function ingredientsToText(ingredients: Ingredient[]): string {
  const lines: string[] = []
  let group: string | null = null
  for (const ingredient of ingredients) {
    const next = ingredient.groupName ?? null
    if (next !== group) {
      if (lines.length) lines.push('')
      if (next) lines.push(`${next}:`)
      group = next
    }
    lines.push(ingredient.rawText)
  }
  return lines.join('\n')
}

const OPTIONAL = /\b(opcional|optional)\b/i
const BULLET = /^\s*(?:[-*•·]|\d+[.)])\s+/

export function textToIngredients(text: string, previous: Ingredient[] = []): IngredientInput[] {
  const byKey = new Map(previous.map((i) => [key(i.groupName ?? null, i.rawText), i]))
  const result: IngredientInput[] = []
  let group: string | null = null

  for (const rawLine of text.split('\n')) {
    const line = rawLine.replace(BULLET, '').trim()
    if (!line) continue
    if (line.endsWith(':') && line.length > 1) {
      group = line.slice(0, -1).trim() || null
      continue
    }
    const kept = byKey.get(key(group, line))
    result.push(
      kept
        ? { ...kept, groupName: group }
        : { rawText: line.slice(0, 255), groupName: group, optional: OPTIONAL.test(line) },
    )
  }
  return result
}

/** Steps: one per line (blank lines and "1." numbering are ignored). */
export function stepsToText(steps: string[]): string {
  return steps.join('\n\n')
}

export function textToSteps(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.replace(BULLET, '').trim())
    .filter(Boolean)
}

function key(group: string | null, rawText: string) {
  return `${group ?? ''}\u0000${rawText}`
}

/** Groups consecutive ingredients by their sub-heading, for display. */
export function groupIngredients(ingredients: Ingredient[]): { name: string | null; items: Ingredient[] }[] {
  const groups: { name: string | null; items: Ingredient[] }[] = []
  for (const ingredient of ingredients) {
    const name = ingredient.groupName ?? null
    const last = groups.at(-1)
    if (last && last.name === name) last.items.push(ingredient)
    else groups.push({ name, items: [ingredient] })
  }
  return groups
}
