import type { MealPlan, MealPlanEntry } from '@/api/plan'

/** Opening a recipe from a meal carries it along: its servings, and which meal to rate after cooking. */
export function mealQuery(plan: MealPlan, entry: MealPlanEntry): string {
  return entry.leftoverOfId ? '' : `?servings=${entry.servings}&plan=${plan.id}&entry=${entry.id}`
}
