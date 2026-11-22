import type { TFunction } from 'i18next'
import type { MealPlanEntry } from '@/api/plan'
import { conflictText } from '@/lib/taste'

const WARNINGS = new Set(['notesMisfit', 'overTime', 'repeat', 'relaxedWeekly', 'relaxedAvoid', 'nothingAllowed', 'breaks'])

/**
 * Why the planner chose a meal's recipe (App\Planning\WeekSampler), in words: what's good about
 * it, and what it had to give up on the household's likings.
 */
export function mealReasons(entry: MealPlanEntry, t: TFunction, language: string): { good: string[]; warnings: string[] } {
  const good: string[] = []
  const warnings: string[] = []
  const lang = language.startsWith('en') ? 'en' : 'es'
  const meal = t(`recipe.meal.${entry.slot ?? 'dinner'}`).toLowerCase()
  for (const reason of entry.reasons ?? []) {
    const conflicts = (reason.conflicts ?? []).map((c) => conflictText(c, t, language)).join(' · ')
    switch (reason.code) {
      case 'asked':
        good.push(t('plan.why.asked'))
        break
      case 'heaviness':
        good.push(t('plan.why.heaviness', { meal, heaviness: t(`recipe.facts.heaviness.${reason.value}`).toLowerCase() }))
        break
      case 'shares':
        good.push(t('plan.why.shares', { names: (reason.names ?? []).map((n) => n[lang]).join(', ') }))
        break
      case 'notesFit':
        good.push(t('plan.why.notesFit', { reason: (reason.names ?? []).map((n) => n[lang]).join(', ') }))
        break
      case 'notesMisfit':
        warnings.push(t('plan.why.notesMisfit', { reason: (reason.names ?? []).map((n) => n[lang]).join(', ') }))
        break
      case 'relaxedAvoid':
        warnings.push(t('plan.why.relaxedAvoid', { conflicts }))
        break
      case 'breaks':
        warnings.push(t('plan.why.breaks', { conflicts }))
        break
      default:
        warnings.push(t(`plan.why.${reason.code}`, { meal }))
    }
  }
  return { good, warnings }
}

export function hasWarning(entry: MealPlanEntry): boolean {
  return (entry.reasons ?? []).some((r) => WARNINGS.has(r.code))
}
