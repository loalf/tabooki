import { BookOpen, ChefHat, Clock, Lock, LockOpen, Minus, MoreHorizontal, Plus, Shuffle, Star, UtensilsCrossed } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { type MealPlan, type MealPlanEntry, type Slot, useAddMeal, useRemoveMeal, useSwapMeal, useUpdateMeal } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatWeekday } from '@/components/plan/format'
import { RecipePicker } from '@/components/plan/RecipePicker'
import { StarRating } from '@/components/StarRating'
import { Button, linkButtonClass } from '@/components/ui'
import { cn } from '@/lib/utils'
import { addDays, today } from '@/lib/week'

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
          className="flex h-12 w-full items-center gap-2 rounded-md border-2 border-dashed border-muted-foreground/60 px-3.5 text-sm font-semibold text-muted-foreground hover:bg-muted disabled:opacity-60"
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
  // Opening the recipe from here carries the meal: its servings, and which meal to rate after cooking.
  const meal = leftoverOf ? '' : `?servings=${entry.servings}&plan=${plan.id}&entry=${entry.id}`
  // Once it's been eaten (or is about to be, today), ask how it was: only for the last few days,
  // so an unrated week doesn't nag forever. Leftovers are the same dish.
  const now = today()
  const askRating = Boolean(recipe) && !leftoverOf && entry.rating == null && entry.date! <= now && entry.date! >= addDays(now, -2)

  return (
    <li className={cn('rounded-lg sticker', entry.locked ? 'bg-accent' : 'bg-card', busy && 'opacity-60')}>
      <div className="flex items-center gap-3 p-2">
        <Thumbnail src={recipe?.source?.thumbnail} className="size-14 shrink-0 rounded-[11px] border-2 border-outline" />
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold text-muted-foreground">
            {t(`recipe.meal.${slot}`)} · {t('recipe.servingsCount', { count: entry.servings })}
          </p>
          {recipe ? (
            <Link to={`/recipes/${recipe.id}${meal}`} className="line-clamp-2 text-[15px] leading-snug font-bold hover:underline">
              {recipe.title}
            </Link>
          ) : (
            <p className="text-sm text-muted-foreground">{t('plan.noRecipe')}</p>
          )}
          {leftoverOf ? (
            <p className="text-xs font-bold text-primary">{t('plan.leftoversOf', { day: formatWeekday(leftoverOf.date!, i18n.language) })}</p>
          ) : (
            (recipe?.totalMinutes != null || entry.rating != null) && (
              <p className="inline-flex items-center gap-3 text-xs text-muted-foreground">
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
            className={cn(entry.locked && 'text-foreground')}
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

      {askRating && (
        <div className="flex flex-wrap items-center justify-between gap-x-2 border-t-2 border-dashed border-muted-foreground/40 py-1 pr-1 pl-3">
          <span className="text-sm font-bold">{t('plan.howWasIt')}</span>
          <StarRating value={null} clearable={false} label={t('plan.howWasIt')} onChange={(rating) => rating && update.mutate({ ...ids, rating })} />
        </div>
      )}

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
          {recipe && (
            <Link to={`/recipes/${recipe.id}/cook${meal}`} className={linkButtonClass}>
              <ChefHat className="size-4" aria-hidden />
              {t('cook.open')}
            </Link>
          )}
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
