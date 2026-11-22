import type { TFunction } from 'i18next'
import type { RecipeListItem } from '@/api/library'
import type { TasteConflict, TasteImpact, TasteRules } from '@/api/taste'
import { FOOD_GROUPS, type FoodGroup } from '@/lib/facts'

/** Meat or fish: what "animal protein twice a week" counts (App\Enum\FoodGroup::animalProtein()). */
export const ANIMAL_PROTEIN: FoodGroup[] = ['red_meat', 'pork', 'poultry', 'processed_meat', 'fish', 'shellfish']

/** One line of the review screen and the settings page: a rule, said in words. */
export type RuleLine = {
  id: string
  text: string
  /** Recipes it leaves out of the library, when it does. */
  removes?: number | null
  firm?: boolean
  /** The rules without this one. */
  without: () => TasteRules
}

type Lang = 'es' | 'en'

const lang = (language: string): Lang => (language.startsWith('en') ? 'en' : 'es')

const same = (a: readonly string[], b: readonly string[]) => a.length === b.length && a.every((x) => b.includes(x))

function groupsText(groups: FoodGroup[], t: TFunction): string {
  if (same(groups, ANIMAL_PROTEIN)) return t('taste.rule.meatOrFish')
  if (same(groups, [...ANIMAL_PROTEIN, 'egg', 'dairy', 'cheese'])) return t('taste.rule.animalProtein')
  if (same(groups, ['fish', 'shellfish'])) return t('taste.rule.fishOrShellfish')
  return groups.map((g) => t(`recipe.facts.group.${g}`)).join(', ')
}

export function ruleLines(rules: TasteRules, impact: TasteImpact | undefined, t: TFunction, language: string): RuleLine[] {
  const l = lang(language)
  const lines: RuleLine[] = []

  if (rules.diet) {
    lines.push({ id: 'diet', text: t(`taste.rule.diet.${rules.diet}`), removes: impact?.diet, firm: true, without: () => ({ ...rules, diet: null }) })
  }
  rules.excludeGroups.forEach((rule, i) =>
    lines.push({
      id: `group-${rule.group}`,
      text: t(`taste.rule.${rule.strength}`, { what: t(`recipe.facts.group.${rule.group}`) }),
      removes: impact?.excludeGroups?.[i],
      firm: rule.strength === 'never',
      without: () => ({ ...rules, excludeGroups: rules.excludeGroups.filter((_, j) => j !== i) }),
    }),
  )
  rules.excludeIngredients.forEach((rule, i) =>
    lines.push({
      id: `ingredient-${i}`,
      text: t(`taste.rule.${rule.strength}`, { what: rule.name[l] }),
      removes: impact?.excludeIngredients?.[i],
      firm: rule.strength === 'never',
      without: () => ({ ...rules, excludeIngredients: rules.excludeIngredients.filter((_, j) => j !== i) }),
    }),
  )
  rules.weekly.forEach((rule, i) => {
    const what = groupsText(rule.groups, t)
    const text =
      rule.max != null && rule.min != null
        ? t('taste.rule.between', { what, min: rule.min, max: rule.max })
        : rule.max != null
          ? t('taste.rule.atMost', { what, count: rule.max })
          : t('taste.rule.atLeast', { what, count: rule.min ?? 0 })
    lines.push({ id: `weekly-${i}`, text, without: () => ({ ...rules, weekly: rules.weekly.filter((_, j) => j !== i) }) })
  })
  const { lunch, dinner } = rules.mealHeaviness
  if (lunch || dinner) {
    const parts = [
      lunch && t('taste.rule.mealIs', { meal: t('recipe.meal.lunch'), heaviness: t(`recipe.facts.heaviness.${lunch}`).toLowerCase() }),
      dinner && t('taste.rule.mealIs', { meal: t('recipe.meal.dinner'), heaviness: t(`recipe.facts.heaviness.${dinner}`).toLowerCase() }),
    ].filter(Boolean)
    lines.push({ id: 'meals', text: parts.join(' · '), without: () => ({ ...rules, mealHeaviness: { lunch: null, dinner: null } }) })
  }
  rules.prefer.forEach((rule, i) =>
    lines.push({
      id: `prefer-${i}`,
      text: t(`taste.rule.${rule.weight}`, { what: rule.name[l] }),
      without: () => ({ ...rules, prefer: rules.prefer.filter((_, j) => j !== i) }),
    }),
  )
  if (rules.maxSpice != null) {
    lines.push({ id: 'spice', text: t(`taste.rule.spice.${rules.maxSpice}`), without: () => ({ ...rules, maxSpice: null }) })
  }
  return lines
}

/** "Alergia: pescado", "Lleva queso", "No es vegetariana": why a recipe doesn't fit. */
export function conflictText(conflict: TasteConflict, t: TFunction, language: string): string {
  const what =
    conflict.kind === 'group' && conflict.value && (FOOD_GROUPS as string[]).includes(conflict.value)
      ? t(`recipe.facts.group.${conflict.value}`)
      : (conflict.name?.[lang(language)] ?? '')
  switch (conflict.kind) {
    case 'diet':
      return t(`taste.conflict.diet.${conflict.value}`)
    case 'unknown':
      return t('taste.conflict.unknown')
    default:
      return conflict.strength === 'never' ? t('taste.conflict.never', { what }) : t('taste.conflict.avoid', { what })
  }
}

/** The firmest conflict, said in words; null when the recipe fits (or the household hasn't said). */
export function misfit(conflicts: TasteConflict[] | null | undefined, t: TFunction, language: string): { text: string; firm: boolean } | null {
  const first = conflicts?.[0]
  return first ? { text: conflictText(first, t, language), firm: first.strength === 'never' } : null
}

/** Warnings for the review screen: too little left to plan a varied week. */
export function impactWarnings(impact: TasteImpact | undefined, t: TFunction): string[] {
  if (!impact || impact.library === 0) return []
  const warnings: string[] = []
  const few = (count: number) => count < 7
  if (few(impact.lunch)) warnings.push(t('taste.review.fewFor', { count: impact.lunch, meal: t('recipe.meal.lunch').toLowerCase() }))
  if (few(impact.dinner)) warnings.push(t('taste.review.fewFor', { count: impact.dinner, meal: t('recipe.meal.dinner').toLowerCase() }))
  if (impact.lunchMatching != null && few(impact.lunchMatching))
    warnings.push(t('taste.review.fewMatching', { count: impact.lunchMatching, meal: t('recipe.meal.lunch').toLowerCase() }))
  if (impact.dinnerMatching != null && few(impact.dinnerMatching))
    warnings.push(t('taste.review.fewMatching', { count: impact.dinnerMatching, meal: t('recipe.meal.dinner').toLowerCase() }))
  return warnings
}

export type NotesFit = NonNullable<RecipeListItem['notesFit']>

/** Whether what the notes say about a recipe is about this meal ("nothing fiddly on weekdays" isn't about Saturday). */
export function notesApply(fit: NotesFit, slot: 'lunch' | 'dinner', weekday: boolean): boolean {
  switch (fit.when) {
    case 'weekdays':
      return weekday
    case 'weekends':
      return !weekday
    case 'lunch':
    case 'dinner':
      return fit.when === slot
    default:
      return true
  }
}

/** The notes' reason in the reader's language ("Laboriosa para entre semana"). */
export function notesReason(fit: NotesFit | null | undefined, language: string): string | null {
  return fit?.reason?.[lang(language)] ?? null
}
