import type { Recipe } from '@/api/library'

/** How filling and spicy a recipe is and what it contains (docs/PLAN.md §6.2). */
export type Heaviness = NonNullable<Recipe['heaviness']>
export type FoodGroup = NonNullable<Recipe['foodGroups']>[number]

export const HEAVINESS: Heaviness[] = ['light', 'medium', 'hearty']
export const SPICE = [0, 1, 2] as const

/** In the order people think of them: meat and fish, then the rest. */
export const FOOD_GROUPS: FoodGroup[] = [
  'red_meat',
  'pork',
  'poultry',
  'processed_meat',
  'fish',
  'shellfish',
  'animal_stock',
  'egg',
  'dairy',
  'cheese',
  'gluten',
  'tree_nuts',
  'peanuts',
  'soy',
  'sesame',
  'alcohol',
]

export function sortGroups(groups: readonly FoodGroup[]): FoodGroup[] {
  return FOOD_GROUPS.filter((group) => groups.includes(group))
}
