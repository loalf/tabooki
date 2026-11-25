import { UtensilsCrossed } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { type MealPlan, type MealPlanEntry, useRemoveMeal, useRestoreMeal } from '@/api/plan'
import { toast } from '@/lib/toast'

/**
 * Takes a meal off the menu ("Fuera"), which is one tap, so it can be taken back for a few
 * seconds. The undo runs after whatever showed the meal has gone (the sheet closed): mutateAsync,
 * because callbacks given to mutate() don't run once it's unmounted.
 */
export function useTakeOffMenu() {
  const { t } = useTranslation()
  const remove = useRemoveMeal()
  const restore = useRestoreMeal()

  const takeOff = (plan: MealPlan, entry: MealPlanEntry, onDone?: () => void) => {
    // Taken now: removing it unlinks the meals that were its leftovers, and the undo links them again.
    const leftovers = (plan.entries ?? []).filter((e) => e.leftoverOfId === entry.id).map((e) => e.id!)
    const back = () =>
      restore.mutateAsync({
        planId: plan.id!,
        entry: entry.id!,
        date: entry.date!,
        slot: entry.slot ?? 'dinner',
        servings: entry.servings!,
        recipe: entry.recipe?.id ?? null,
        locked: entry.locked ?? false,
        rating: entry.rating ?? null,
        cooked: entry.cooked ?? null,
        reasons: entry.reasons ?? [],
        leftoverOf: entry.leftoverOfId ?? null,
        leftovers,
      })

    remove.mutate(
      { planId: plan.id!, entry: entry.id! },
      {
        onSuccess: () => {
          onDone?.()
          toast({
            tone: 'success',
            icon: UtensilsCrossed,
            title: t('plan.removed', { meal: t(`recipe.meal.${entry.slot}`) }),
            action: { label: t('common.undo'), undo: () => void back().catch(() => toast({ tone: 'error', title: t('plan.restoreError') })) },
          })
        },
      },
    )
  }

  return { takeOff, isPending: remove.isPending, isError: remove.isError }
}
