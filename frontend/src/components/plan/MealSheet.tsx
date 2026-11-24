import { BookOpen, ChefHat, Lock, LockOpen, Minus, Plus, RefreshCw, Search, Sparkles, Star, TriangleAlert, UtensilsCrossed, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useRecipes } from '@/api/library'
import { type MealPlan, type MealPlanEntry, useSwapMeal, useUpdateMeal } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatDay, formatWeekday } from '@/components/plan/format'
import { mealQuery } from '@/components/plan/meal'
import { mealReasons } from '@/components/plan/reasons'
import { RecipePicker } from '@/components/plan/RecipePicker'
import { SwapWhy } from '@/components/plan/SwapWhy'
import { useTakeOffMenu } from '@/components/plan/useTakeOffMenu'
import { Sheet } from '@/components/Sheet'
import { Button } from '@/components/ui'
import { type Reason, suggestFor } from '@/lib/suggest'
import { askWhySwap } from '@/lib/swapWhy'
import { conflictText } from '@/lib/taste'
import { cn } from '@/lib/utils'
import { parseIsoDate } from '@/lib/week'

/**
 * Everything about one planned meal, instead of a row of icons on every meal: cook it, open it,
 * fix it, drop it, and swap it for one of three suggestions you can see (each says why).
 */
export function MealSheet({ plan, entry, onClose }: { plan: MealPlan; entry: MealPlanEntry; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const update = useUpdateMeal()
  const { takeOff, isPending: removing, isError: removeFailed } = useTakeOffMenu()
  const swap = useSwapMeal()
  const [picking, setPicking] = useState(false)
  const recipe = entry.recipe
  const ids = { planId: plan.id!, entry: entry.id! }
  const busy = update.isPending || removing || swap.isPending
  const query = mealQuery(plan, entry)
  const leftoverOf = entry.leftoverOfId ? plan.entries?.find((e) => e.id === entry.leftoverOfId) : undefined

  // After a swap, ask why (once in a while that teaches the household's likings).
  const [asking, setAsking] = useState<{ id: string; title: string } | null>(null)
  const swappedOut = recipe?.id && !leftoverOf ? { id: recipe.id, title: recipe.title ?? '' } : null
  const swapped = () => (swappedOut && askWhySwap() ? setAsking(swappedOut) : onClose())
  const choose = (id: string) => update.mutate({ ...ids, recipe: id }, { onSuccess: () => (id === recipe?.id ? onClose() : swapped()) })

  const out = () => takeOff(plan, entry, onClose)

  return (
    <Sheet title={t('plan.sheet.title', { day: formatDay(entry.date!, i18n.language), meal: t(`recipe.meal.${entry.slot}`) })} onClose={onClose} hideTitle>
      {asking ? (
        <SwapWhy recipe={asking} entry={entry} onDone={onClose} />
      ) : (
        <div className={cn('flex flex-col gap-5', busy && 'opacity-70')}>
          <header className="flex items-center gap-3">
            <Thumbnail src={recipe?.source?.thumbnail} className="size-13 shrink-0 rounded-[11px] border-2 border-outline" />
            <div className="min-w-0">
              <p className="text-[11px] font-extrabold tracking-wider text-muted-foreground uppercase">
                {formatDay(entry.date!, i18n.language)} · {t(`recipe.meal.${entry.slot}`)}
              </p>
              <p className="line-clamp-2 text-lg leading-tight font-extrabold">{recipe?.title ?? t('plan.noRecipe')}</p>
              {leftoverOf && <p className="text-xs font-bold text-primary">{t('plan.leftoversOf', { day: formatWeekday(leftoverOf.date!, i18n.language) })}</p>}
            </div>
          </header>
          <Why entry={entry} />

          <div className="grid grid-cols-4 gap-2">
            {recipe && !leftoverOf ? (
              <Link to={`/recipes/${recipe.id}/cook${query}`} className={tileClass('primary')}>
                <ChefHat aria-hidden />
                {t('cook.open')}
              </Link>
            ) : (
              <span className={cn(tileClass('primary'), 'opacity-50')} aria-disabled>
                <ChefHat aria-hidden />
                {t('cook.open')}
              </span>
            )}
            {recipe ? (
              <Link to={`/recipes/${recipe.id}${query}`} className={tileClass()}>
                <BookOpen aria-hidden />
                {t('plan.sheet.view')}
              </Link>
            ) : (
              <span className={cn(tileClass(), 'opacity-50')} aria-disabled>
                <BookOpen aria-hidden />
                {t('plan.sheet.view')}
              </span>
            )}
            <button type="button" className={tileClass(entry.locked ? 'on' : undefined)} disabled={busy} aria-pressed={entry.locked} onClick={() => update.mutate({ ...ids, locked: !entry.locked })}>
              {entry.locked ? <Lock aria-hidden /> : <LockOpen aria-hidden />}
              {entry.locked ? t('plan.sheet.locked') : t('plan.sheet.lock')}
            </button>
            <button type="button" className={tileClass()} disabled={busy} onClick={out}>
              <UtensilsCrossed aria-hidden />
              {t('plan.sheet.out')}
            </button>
          </div>
          {entry.locked && <p className="-mt-3 text-xs text-muted-foreground">{t('plan.sheet.lockedHint')}</p>}

          <div className="flex items-center justify-between gap-3">
            <span className="font-extrabold">{t('recipe.servings')}</span>
            <div className="flex items-center rounded-full border-2 border-outline bg-card" role="group" aria-label={t('recipe.servings')}>
              <button
                type="button"
                className="grid size-10 place-items-center disabled:opacity-40"
                disabled={busy || (entry.servings ?? 1) <= 1}
                aria-label={t('plan.fewer')}
                onClick={() => update.mutate({ ...ids, servings: (entry.servings ?? 1) - 1 })}
              >
                <Minus className="size-4" aria-hidden />
              </button>
              <span className="min-w-22 text-center text-sm font-bold tabular-nums">{t('recipe.servingsCount', { count: entry.servings })}</span>
              <button
                type="button"
                className="grid size-10 place-items-center disabled:opacity-40"
                disabled={busy || (entry.servings ?? 1) >= 50}
                aria-label={t('plan.more')}
                onClick={() => update.mutate({ ...ids, servings: (entry.servings ?? 1) + 1 })}
              >
                <Plus className="size-4" aria-hidden />
              </button>
            </div>
          </div>

          <Suggestions plan={plan} entry={entry} disabled={busy} onChoose={choose} />

          <div className="flex flex-col gap-2">
            <Button variant="secondary" className="justify-start text-muted-foreground" onClick={() => setPicking(true)} disabled={busy}>
              <Search className="size-5" aria-hidden />
              <span className="flex-1 text-left">{t('plan.sheet.pick')}</span>
            </Button>
            <Button variant="ghost" size="sm" className="self-start text-primary" disabled={busy} onClick={() => swap.mutate(ids, { onSuccess: swapped })}>
              <Wand2 className={cn('size-4', swap.isPending && 'animate-spin')} aria-hidden />
              {t('plan.sheet.surprise')}
            </Button>
          </div>
          {(update.isError || removeFailed || swap.isError) && <p className="text-sm text-destructive">{t('plan.error')}</p>}
        </div>
      )}

      {picking && (
        <RecipePicker
          slot={entry.slot ?? 'dinner'}
          onClose={() => setPicking(false)}
          onPick={(id) => {
            setPicking(false)
            choose(id)
          }}
        />
      )}
    </Sheet>
  )
}

/** What the planner says about this meal: why it's a good pick, and what it had to give up. */
function Why({ entry }: { entry: MealPlanEntry }) {
  const { t, i18n } = useTranslation()
  const { good, warnings } = mealReasons(entry, t, i18n.language)
  if (!good.length && !warnings.length) return null
  return (
    <div className="-mt-2 flex flex-col gap-2">
      {good.length > 0 && (
        <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
          <Sparkles className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
          <span className="first-letter:uppercase">{good.join(' · ')}</span>
        </p>
      )}
      {warnings.map((warning) => (
        <p key={warning} className="flex items-start gap-1.5 rounded-md bg-accent px-2.5 py-2 text-sm font-semibold text-accent-foreground">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>{warning}</span>
        </p>
      ))}
    </div>
  )
}

function tileClass(variant?: 'primary' | 'on') {
  return cn(
    'flex flex-col items-center justify-center gap-1.5 rounded-md sticker-sm px-1 py-3 text-xs font-bold [&>svg]:size-5 disabled:opacity-50',
    variant === 'primary' ? 'bg-primary text-primary-foreground' : variant === 'on' ? 'bg-accent text-accent-foreground' : 'bg-card',
  )
}

function Suggestions({ plan, entry, disabled, onChoose }: { plan: MealPlan; entry: MealPlanEntry; disabled: boolean; onChoose: (id: string) => void }) {
  const { t, i18n } = useTranslation()
  const { data: library = [] } = useRecipes({ q: '', tags: [], favorite: false })
  const [round, setRound] = useState(0)
  const [seen, setSeen] = useState<string[]>([])
  const current = entry.recipe?.id ? [entry.recipe.id] : []
  const exclude = [...seen, ...current]
  let suggestions = suggestFor({ library, plan, entry, round, exclude })
  // Seen them all: start over rather than show nothing.
  if (!suggestions.length && seen.length) suggestions = suggestFor({ library, plan, entry, round, exclude: current })

  if (!suggestions.length) return null

  const more = () => {
    setSeen((current) => [...current, ...suggestions.map((s) => s.recipe.id!)])
    setRound((r) => r + 1)
  }

  return (
    <section aria-labelledby="swap-title" className="flex flex-col gap-2.5">
      <div className="flex items-center justify-between">
        <h3 id="swap-title" className="text-[17px] font-extrabold">
          {t('plan.sheet.swapFor')}
        </h3>
        <button type="button" onClick={more} className="inline-flex h-9 items-center gap-1.5 text-sm font-bold text-primary">
          <RefreshCw className="size-4" aria-hidden />
          {t('plan.sheet.others')}
        </button>
      </div>
      <ul className="no-scrollbar -mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pt-0.5 pb-1.5">
        {suggestions.map(({ recipe, reason }) => (
          <li key={recipe.id} className="flex w-[12.5rem] shrink-0 snap-start">
            <button
              type="button"
              disabled={disabled}
              onClick={() => onChoose(recipe.id!)}
              className="flex w-full flex-col overflow-hidden rounded-lg sticker-sm bg-card text-left disabled:opacity-60"
            >
              <Thumbnail src={recipe.source?.thumbnail} className="aspect-[2/1] w-full border-b-2 border-outline" />
              <span className="flex flex-1 flex-col gap-1.5 px-2.5 pt-2 pb-2.5">
                <span className="line-clamp-2 text-sm leading-snug font-bold">{recipe.title}</span>
                {recipe.tasteConflicts?.length ? (
                  <span className="mt-auto text-[11px] font-semibold text-muted-foreground">{conflictText(recipe.tasteConflicts[0], t, i18n.language)}</span>
                ) : (
                  <ReasonPill reason={reason} />
                )}
              </span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  )
}

function ReasonPill({ reason }: { reason: Reason }) {
  const { t, i18n } = useTranslation()
  const text = (() => {
    switch (reason.kind) {
      case 'queued':
        return t('plan.reason.queued')
      case 'favorite':
        return t('plan.reason.favorite')
      case 'rated':
        return t('plan.reason.rated', { count: reason.stars })
      case 'never':
        return t('plan.reason.never')
      case 'notSince':
        return t('plan.reason.notSince', { month: new Intl.DateTimeFormat(i18n.language, { month: 'long' }).format(parseIsoDate(reason.date)) })
      case 'quick':
        return t('plan.reason.quick', { count: reason.minutes })
      case 'minutes':
        return reason.minutes ? t('recipe.minutes', { count: reason.minutes }) : t('plan.reason.idea')
    }
  })()
  const Icon = reason.kind === 'rated' ? Star : Sparkles
  return (
    <span className="mt-auto inline-flex items-center gap-1 self-start rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold text-accent-foreground">
      <Icon className="size-3 shrink-0 text-brand" aria-hidden />
      {text}
    </span>
  )
}
