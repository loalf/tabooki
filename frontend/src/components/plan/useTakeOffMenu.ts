import { UtensilsCrossed } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { type MealPlan, type MealPlanEntry, useAddMeal, useRemoveMeal, useUpdateMeal } from '@/api/plan'
import { toast } from '@/lib/toast'

/**
 * Takes a meal off the menu ("Fuera", "No lo hicimos"), which is one tap, so it can be taken back
 * for a few seconds. The undo runs after whatever showed the meal has gone (the sheet closed, the
 * card left Hoy): mutateAsync, because callbacks given to mutate() don't run once it's unmounted.
 */
export function useTakeOffMenu() {
  const { t } = useTranslation()
  const add = useAddMeal()
  const remove = useRemoveMeal()
  const update = useUpdateMeal()

  // Back on the same day and meal, with its servings, recipe and lock. If other meals were its
  // leftovers, they stay apart: the API can't link them again.
  const restore = async (plan: MealPlan, entry: MealPlanEntry) => {
    const week = await add.mutateAsync({ planId: plan.id!, date: entry.date!, slot: entry.slot ?? 'dinner', servings: entry.servings })
    const back = week.entries?.find((e) => e.date === entry.date && e.slot === entry.slot)
    if (back?.id && (entry.recipe?.id || entry.locked)) {
      await update.mutateAsync({ planId: plan.id!, entry: back.id, recipe: entry.recipe?.id, locked: entry.locked || undefined })
    }
  }

  const takeOff = (plan: MealPlan, entry: MealPlanEntry, onDone?: () => void) =>
    remove.mutate(
      { planId: plan.id!, entry: entry.id! },
      {
        onSuccess: () => {
          onDone?.()
          toast({
            tone: 'success',
            icon: UtensilsCrossed,
            title: t('plan.removed', { meal: t(`recipe.meal.${entry.slot}`) }),
            action: { label: t('common.undo'), undo: () => void restore(plan, entry).catch(() => toast({ tone: 'error', title: t('plan.restoreError') })) },
          })
        },
      },
    )

  return { takeOff, isPending: remove.isPending, isError: remove.isError }
}
