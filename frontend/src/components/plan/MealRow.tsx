import { Clock, Lock, LockOpen, Minus, MoreHorizontal, Plus, Shuffle, UtensilsCrossed, BookOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { type MealPlan, type MealPlanEntry, type Slot, useAddMeal, useRemoveMeal, useSwapMeal, useUpdateMeal } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatWeekday } from '@/components/plan/format'
import { RecipePicker } from '@/components/plan/RecipePicker'
import { Button } from '@/components/ui'
import { cn } from '@/lib/utils'

/** One lunch or dinner of the week: planned (with its actions) or not (a button to plan it). */
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
          className="flex h-11 w-full items-center gap-2 rounded-md border border-dashed px-3 text-sm text-muted-foreground hover:bg-muted disabled:opacity-60"
        >
          <Plus className="size-4" aria-hidden />
          {t('plan.add', { meal: t(`recipe.meal.${slot}`).toLowerCase() })}
        </button>
      </li>
    )
  }
  return <PlannedMeal plan={plan} entry={entry} slot={slot} />
}

function PlannedMeal({ plan, entry, slot }: { plan: MealPlan; entry: MealPlanEntry; slot: Slot }) {
  const { t, i18n } = useTranslation()
  const swap = useSwapMeal()
  const update = useUpdateMeal()
  const remove = useRemoveMeal()
  const [open, setOpen] = useState(false)
  const [picking, setPicking] = useState(false)
  const recipe = entry.recipe
  const busy = swap.isPending || update.isPending || remove.isPending
  const ids = { planId: plan.id!, entry: entry.id! }
  const leftoverOf = entry.leftoverOfId ? plan.entries?.find((e) => e.id === entry.leftoverOfId) : undefined

  return (
    <li className={cn('rounded-md border bg-card', entry.locked && 'border-primary/50', busy && 'opacity-60')}>
      <div className="flex items-center gap-3 p-2">
        <Thumbnail src={recipe?.source?.thumbnail} className="size-14 shrink-0 rounded-md" />
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium text-muted-foreground">
            {t(`recipe.meal.${slot}`)} · {t('recipe.servingsCount', { count: entry.servings })}
          </p>
          {recipe ? (
            <Link to={`/recipes/${recipe.id}`} className="line-clamp-2 leading-snug font-medium hover:underline">
              {recipe.title}
            </Link>
          ) : (
            <p className="text-sm text-muted-foreground">{t('plan.noRecipe')}</p>
          )}
          {leftoverOf ? (
            <p className="text-xs text-primary">{t('plan.leftoversOf', { day: formatWeekday(leftoverOf.date!, i18n.language) })}</p>
          ) : (
            recipe?.totalMinutes != null && (
              <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Clock className="size-3" aria-hidden />
                {t('recipe.minutes', { count: recipe.totalMinutes })}
              </p>
            )
          )}
        </div>
        <div className="flex shrink-0">
          <Button
            variant="ghost"
            size="icon"
            disabled={busy}
            aria-pressed={entry.locked}
            aria-label={entry.locked ? t('plan.unlock') : t('plan.lock')}
            onClick={() => update.mutate({ ...ids, locked: !entry.locked })}
            className={cn(entry.locked && 'text-primary')}
          >
            {entry.locked ? <Lock className="size-4" aria-hidden /> : <LockOpen className="size-4 text-muted-foreground" aria-hidden />}
          </Button>
          <Button variant="ghost" size="icon" disabled={busy || entry.locked} aria-label={t('plan.swap')} onClick={() => swap.mutate(ids)}>
            <Shuffle className={cn('size-4', swap.isPending && 'animate-spin')} aria-hidden />
          </Button>
          <Button variant="ghost" size="icon" aria-expanded={open} aria-label={t('plan.options')} onClick={() => setOpen(!open)}>
            <MoreHorizontal className="size-4" aria-hidden />
          </Button>
        </div>
      </div>

      {open && (
        <div className="flex flex-wrap items-center gap-2 border-t px-2 py-2">
          <div className="flex items-center gap-1" role="group" aria-label={t('recipe.servings')}>
            <Button
              variant="secondary"
              size="sm"
              disabled={busy || (entry.servings ?? 1) <= 1}
              aria-label={t('plan.fewer')}
              onClick={() => update.mutate({ ...ids, servings: (entry.servings ?? 1) - 1 })}
            >
              <Minus className="size-4" aria-hidden />
            </Button>
            <span className="min-w-20 px-1 text-center text-sm whitespace-nowrap tabular-nums">{t('recipe.servingsCount', { count: entry.servings })}</span>
            <Button
              variant="secondary"
              size="sm"
              disabled={busy || (entry.servings ?? 1) >= 50}
              aria-label={t('plan.more')}
              onClick={() => update.mutate({ ...ids, servings: (entry.servings ?? 1) + 1 })}
            >
              <Plus className="size-4" aria-hidden />
            </Button>
          </div>
          <Button variant="secondary" size="sm" disabled={busy} onClick={() => setPicking(true)}>
            <BookOpen className="size-4" aria-hidden />
            {t('plan.choose')}
          </Button>
          <Button variant="ghost" size="sm" className="ml-auto text-destructive" disabled={busy} onClick={() => remove.mutate(ids)}>
            <UtensilsCrossed className="size-4" aria-hidden />
            {t('plan.remove')}
          </Button>
        </div>
      )}

      {picking && (
        <RecipePicker
          slot={slot}
          onClose={() => setPicking(false)}
          onPick={(id) => {
            setPicking(false)
            setOpen(false)
            update.mutate({ ...ids, recipe: id })
          }}
        />
      )}
    </li>
  )
}
