import type { RecipeListItem } from '@/api/library'
import type { MealPlan, MealPlanEntry } from '@/api/plan'
import { isWeekday, parseIsoDate, today } from '@/lib/week'

/** Why a recipe is suggested, said in a few words on its card. */
export type Reason =
  | { kind: 'queued' }
  | { kind: 'favorite' }
  | { kind: 'rated'; stars: number }
  | { kind: 'never' }
  | { kind: 'notSince'; date: string }
  | { kind: 'quick'; minutes: number }
  | { kind: 'minutes'; minutes: number }

export type Suggestion = { recipe: RecipeListItem; reason: Reason }

const DAY = 24 * 3600 * 1000

function daysSince(date: string | null | undefined, now: string): number | null {
  return date ? Math.round((parseIsoDate(now).getTime() - parseIsoDate(date).getTime()) / DAY) : null
}

/** A small seeded random, so the same round shows the same three and "otras" gives new ones. */
function random(seed: number) {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/**
 * Recipes to swap a meal for, picked like the planner picks (suits the meal and the weekday time
 * limit, not already this week, nor eaten in the last two weeks while there are others; favorites,
 * good ratings and long-forgotten ones weigh more), each with the reason it's worth a look.
 */
export function suggestFor({
  library,
  plan,
  entry,
  count = 3,
  round = 0,
  exclude = [],
}: {
  library: RecipeListItem[]
  plan: MealPlan
  entry: MealPlanEntry
  count?: number
  round?: number
  exclude?: string[]
}): Suggestion[] {
  const now = today()
  const slot = entry.slot ?? 'dinner'
  const inWeek = new Set((plan.entries ?? []).flatMap((e) => (e.recipe?.id ? [e.recipe.id] : [])))
  const limit = entry.date && isWeekday(entry.date) ? plan.weekdayMaxMinutes : null

  const fits = library.filter(
    (r) =>
      r.id &&
      !exclude.includes(r.id) &&
      (!r.mealTypes?.length || r.mealTypes.includes(slot)) &&
      (limit == null || r.totalMinutes == null || r.totalMinutes <= limit),
  )
  // The rules relax, in order, while there aren't enough (a small library): first recently
  // eaten ones come back, then ones already this week.
  const tiers = [
    fits.filter((r) => !inWeek.has(r.id!) && (daysSince(r.lastCookedOn, now) ?? Infinity) > 14),
    fits.filter((r) => !inWeek.has(r.id!)),
    fits,
  ]
  const pool = tiers.find((tier) => tier.length >= count) ?? fits

  const weight = (r: RecipeListItem) => {
    let w = 1
    if (r.planNext) w *= 3
    if (r.favorite) w *= 2
    if (r.rating != null) w *= 0.6 + 0.2 * r.rating
    if (r.needsReview) w *= 0.8
    const days = daysSince(r.lastCookedOn, now)
    w *= 1 + Math.min(days ?? 45, 90) / 90
    return w
  }

  const next = random(round * 7919 + pool.length)
  const left = [...pool]
  const picked: Suggestion[] = []
  while (picked.length < count && left.length) {
    const weights = left.map(weight)
    let target = next() * weights.reduce((a, b) => a + b, 0)
    let i = 0
    while (i < left.length - 1 && (target -= weights[i]) >= 0) i++
    const [recipe] = left.splice(i, 1)
    picked.push({ recipe, reason: reasonFor(recipe, now) })
  }
  return picked
}

function reasonFor(recipe: RecipeListItem, now: string): Reason {
  const days = daysSince(recipe.lastCookedOn, now)
  if (recipe.planNext) return { kind: 'queued' }
  if (recipe.rating != null && recipe.rating >= 4) return { kind: 'rated', stars: recipe.rating }
  if (days !== null && days > 30) return { kind: 'notSince', date: recipe.lastCookedOn! }
  if (recipe.favorite) return { kind: 'favorite' }
  if (recipe.totalMinutes != null && recipe.totalMinutes <= 25) return { kind: 'quick', minutes: recipe.totalMinutes }
  if (days === null) return { kind: 'never' }
  return { kind: 'minutes', minutes: recipe.totalMinutes ?? 0 }
}
