import { Clock, CornerDownRight, Lock, Plus, Star, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type MealPlan, type MealPlanEntry, type Slot, useAddMeal } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatWeekday } from '@/components/plan/format'
import { MealSheet } from '@/components/plan/MealSheet'
import { firstWarning } from '@/components/plan/reasons'
import { cn } from '@/lib/utils'

/** One lunch or dinner of the week: planned (tap it for everything you can do) or not (a button to plan it). */
export function MealRow({ plan, date, slot, entry }: { plan: MealPlan; date: string; slot: Slot; entry?: MealPlanEntry }) {
  const { t } = useTranslation()
  const add = useAddMeal()

  if (!entry) {
    return (
      <li>
        <button
          type="button"
          disabled={add.isPending}
          onClick={() => add.mutate({ planId: plan.id!, date, slot })}
          className="flex h-12 w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/60 px-3.5 text-sm font-semibold text-muted-foreground hover:bg-muted disabled:opacity-60"
        >
          <Plus className="size-4" aria-hidden />
          {t('plan.add', { meal: t(`recipe.meal.${slot}`).toLowerCase() })}
        </button>
      </li>
    )
  }
  return <PlannedMeal plan={plan} entry={entry} slot={slot} />
}

export function PlannedMeal({ plan, entry, slot }: { plan: MealPlan; entry: MealPlanEntry; slot: Slot }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(false)
  const recipe = entry.recipe
  const leftoverOf = entry.leftoverOfId ? plan.entries?.find((e) => e.id === entry.leftoverOfId) : undefined
  // What the planner had to give up, in two or three words; the meal sheet says it in full.
  const warning = firstWarning(entry)
  const warningLabel = warning && (
    <span className="inline-flex items-center gap-1 font-bold text-destructive">
      <TriangleAlert className="size-3" aria-hidden />
      {t(`plan.why.short.${warning}`)}
    </span>
  )

  return (
    <li>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        className={cn('flex w-full items-center gap-3 rounded-lg sticker-sm p-2 text-left', entry.locked ? 'bg-accent' : 'bg-card')}
      >
        <Thumbnail src={recipe?.source?.thumbnail} className="size-14 shrink-0 rounded-[11px] border-2 border-outline" />
        <span className="min-w-0 flex-1">
          <span className="block text-xs font-semibold text-muted-foreground">
            {t(`recipe.meal.${slot}`)} · {t('recipe.servingsCount', { count: entry.servings })}
          </span>
          <span className={cn('line-clamp-2 text-[15px] leading-snug font-bold', !recipe && 'font-normal text-muted-foreground')}>
            {recipe?.title ?? t('plan.noRecipe')}
          </span>
          {leftoverOf ? (
            <span className="inline-flex flex-wrap items-center gap-x-3 text-xs">
              <span className="inline-flex items-center gap-1 font-bold text-primary">
                <CornerDownRight className="size-3" aria-hidden />
                {t('plan.leftoversOf', { day: formatWeekday(leftoverOf.date!, i18n.language) })}
              </span>
              {warningLabel}
            </span>
          ) : (
            (recipe?.totalMinutes != null || entry.rating != null || warning) && (
              <span className="inline-flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                {recipe?.totalMinutes != null && (
                  <span className="inline-flex items-center gap-1">
                    <Clock className="size-3" aria-hidden />
                    {t('recipe.minutes', { count: recipe.totalMinutes })}
                  </span>
                )}
                {entry.rating != null && (
                  <span className="inline-flex items-center gap-1" aria-label={t('plan.rated', { count: entry.rating })}>
                    <Star className="size-3 fill-highlight text-outline" aria-hidden />
                    {entry.rating}
                  </span>
                )}
                {warningLabel}
              </span>
            )
          )}
        </span>
        {entry.locked && (
          <span className="grid size-7 shrink-0 place-items-center rounded-full bg-card" aria-label={t('plan.sheet.locked')}>
            <Lock className="size-3.5" aria-hidden />
          </span>
        )}
      </button>
      {open && <MealSheet plan={plan} entry={entry} onClose={() => setOpen(false)} />}
    </li>
  )
}
