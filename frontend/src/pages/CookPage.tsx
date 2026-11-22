import { ArrowLeft, ArrowRight, Check, ListChecks, Minus, MoonStar, Plus, Sun, Timer as TimerIcon, X } from 'lucide-react'
import { type TouchEvent, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { type Ingredient, type Recipe, useRecipe, useUpdateRecipe } from '@/api/library'
import { useUpdateMeal } from '@/api/plan'
import { type Timer, useTimers } from '@/components/cook/useTimers'
import { useWakeLock } from '@/components/cook/useWakeLock'
import { Eggs } from '@/components/brand'
import { EmptyState } from '@/components/PageHeader'
import { Splash } from '@/components/Splash'
import { StarRating } from '@/components/StarRating'
import { Button, CheckMark } from '@/components/ui'
import { findTimers, formatClock, scaleLine } from '@/lib/cook'
import { groupIngredients } from '@/lib/recipeText'
import { cn } from '@/lib/utils'

/**
 * Cook mode: full screen, the screen stays on, one step at a time in big type, with the
 * ingredients to tick off first and timers from the steps. Opened from the menu it knows the
 * servings and which meal to rate at the end (?servings=6&plan=…&entry=…).
 */
export function CookPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const recipe = useRecipe(id)
  const navigate = useNavigate()

  if (recipe.isPending) return <Splash />
  if (recipe.isError) {
    return (
      <div className="mx-auto flex min-h-dvh max-w-2xl flex-col justify-center gap-4 px-4">
        <EmptyState>{t('recipe.notFound')}</EmptyState>
        <Button variant="secondary" onClick={() => void navigate('/', { replace: true })}>
          {t('library.title')}
        </Button>
      </div>
    )
  }
  return <CookView recipe={recipe.data} />
}

function CookView({ recipe }: { recipe: Recipe }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const id = recipe.id!
  const steps = recipe.steps ?? []
  const ingredients = recipe.ingredients ?? []
  // Pages: 0 ingredients, 1…n the steps, n+1 done.
  const last = steps.length + 1
  const [page, setPage] = useStoredPage(id, last)
  const [servings, setServings] = useState(() => clampServings(Number(params.get('servings')) || recipe.servings || 1))
  const [checked, setChecked] = useState<ReadonlySet<number>>(new Set())
  const [showIngredients, setShowIngredients] = useState(false)
  const wake = useWakeLock()
  const { timers, now, start, stop } = useTimers()
  const factor = servings / (recipe.servings || servings)
  const touch = useRef<{ x: number; y: number } | null>(null)

  const go = (next: number) => {
    setPage(Math.min(last, Math.max(0, next)))
    setShowIngredients(false)
  }
  const close = () => void navigate(`/recipes/${id}${params.size ? `?${params}` : ''}`, { replace: true })

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement) return
      if (event.key === 'ArrowRight') go(page + 1)
      if (event.key === 'ArrowLeft') go(page - 1)
      if (event.key === 'Escape') setShowIngredients(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  const onTouchStart = (event: TouchEvent) => {
    touch.current = { x: event.touches[0].clientX, y: event.touches[0].clientY }
  }
  const onTouchEnd = (event: TouchEvent) => {
    const from = touch.current
    touch.current = null
    if (!from) return
    const dx = event.changedTouches[0].clientX - from.x
    const dy = event.changedTouches[0].clientY - from.y
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) go(page + (dx < 0 ? 1 : -1))
  }

  const toggle = (index: number) =>
    setChecked((current) => {
      const next = new Set(current)
      if (next.has(index)) next.delete(index)
      else next.add(index)
      return next
    })

  return (
    <div className="mx-auto flex min-h-dvh max-w-2xl flex-col px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]">
      <header className="flex items-center gap-2">
        <Button variant="ghost" size="icon" onClick={close} aria-label={t('cook.close')}>
          <X className="size-5" aria-hidden />
        </Button>
        <div className="min-w-0 flex-1">
          <p className="truncate font-bold">{recipe.title}</p>
          <p className="flex items-center gap-1 text-xs text-muted-foreground">
            {wake === 'on' ? <Sun className="size-3.5" aria-hidden /> : <MoonStar className="size-3.5" aria-hidden />}
            {t(`cook.wake.${wake}`)}
          </p>
        </div>
        {page > 0 && page < last && ingredients.length > 0 && (
          <Button variant="ghost" size="icon" aria-expanded={showIngredients} onClick={() => setShowIngredients(!showIngredients)} aria-label={t('recipe.ingredients')}>
            <ListChecks className="size-5" aria-hidden />
          </Button>
        )}
      </header>

      <Progress page={page} last={last} />
      {timers.length > 0 && <TimerBar timers={timers} now={now} onStop={stop} />}

      <main className="flex-1 py-6" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd} aria-live="polite">
        {page === 0 ? (
          <section aria-labelledby="cook-ingredients">
            <h1 id="cook-ingredients" className="font-display text-[1.875rem] leading-tight font-normal text-brand">
              {t('cook.prepare')}
            </h1>
            <div className="mt-3 mb-4 flex items-center gap-2" role="group" aria-label={t('recipe.servings')}>
              <Button variant="secondary" size="icon" disabled={servings <= 1} onClick={() => setServings(clampServings(servings - 1))} aria-label={t('recipe.form.fewer')}>
                <Minus className="size-4" aria-hidden />
              </Button>
              <span className="min-w-24 text-center text-[17px] font-extrabold whitespace-nowrap tabular-nums">{t('recipe.servingsCount', { count: servings })}</span>
              <Button variant="secondary" size="icon" disabled={servings >= 50} onClick={() => setServings(clampServings(servings + 1))} aria-label={t('recipe.form.more')}>
                <Plus className="size-4" aria-hidden />
              </Button>
            </div>
            {factor !== 1 && <p className="mb-3 text-sm text-muted-foreground">{t('cook.scaled', { from: recipe.servings, to: servings })}</p>}
            {ingredients.length === 0 ? (
              <p className="text-muted-foreground">{t('recipe.noIngredients')}</p>
            ) : (
              <IngredientChecklist ingredients={ingredients} factor={factor} checked={checked} onToggle={toggle} locale={i18n.language} large />
            )}
          </section>
        ) : page < last ? (
          <section aria-labelledby="cook-step">
            <p id="cook-step" className="mb-4 text-[15px] font-extrabold text-primary">
              {t('cook.stepOf', { n: page, total: steps.length })}
            </p>
            <p className="text-[1.75rem] leading-[1.4] text-pretty sm:text-3xl">{steps[page - 1]}</p>
            {findTimers(steps[page - 1]).length > 0 && (
              <div className="mt-6 flex flex-wrap gap-2">
                {findTimers(steps[page - 1]).map((timer) => (
                  <Button key={timer.seconds} variant="secondary" onClick={() => start(timer.seconds, timer.label)}>
                    <TimerIcon className="size-4" aria-hidden />
                    {t('cook.startTimer', { time: timer.label })}
                  </Button>
                ))}
              </div>
            )}
          </section>
        ) : (
          <Done recipe={recipe} onAgain={() => go(0)} onClose={close} />
        )}
      </main>

      {page < last && (
        <footer className="grid grid-cols-[auto_1fr] gap-3">
          <Button variant="secondary" size="icon" className="size-14" disabled={page === 0} onClick={() => go(page - 1)} aria-label={t('cook.previous')}>
            <ArrowLeft className="size-6" aria-hidden />
          </Button>
          <Button className="h-14 text-lg font-bold" onClick={() => go(page + 1)}>
            {page === 0 ? t('cook.start') : page === last - 1 ? t('cook.finish') : t('cook.next')}
            {page === last - 1 ? <Check className="size-5" aria-hidden /> : <ArrowRight className="size-5" aria-hidden />}
          </Button>
        </footer>
      )}

      {showIngredients && (
        <div className="fixed inset-0 z-20 flex flex-col justify-end bg-foreground/30" onClick={() => setShowIngredients(false)}>
          <div
            role="dialog"
            aria-label={t('recipe.ingredients')}
            className="mx-auto max-h-[75dvh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-background p-4 pb-[max(1rem,env(safe-area-inset-bottom))]"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-2 flex items-center justify-between">
              <h2 className="font-semibold">
                {t('recipe.ingredients')} · {t('recipe.servingsCount', { count: servings })}
              </h2>
              <Button variant="ghost" size="icon" onClick={() => setShowIngredients(false)} aria-label={t('cook.closeIngredients')}>
                <X className="size-5" aria-hidden />
              </Button>
            </div>
            <IngredientChecklist ingredients={ingredients} factor={factor} checked={checked} onToggle={toggle} locale={i18n.language} />
          </div>
        </div>
      )}
    </div>
  )
}

function clampServings(n: number) {
  return Math.min(50, Math.max(1, Math.round(n)))
}

/** Where you were, so a reload (or iOS dropping the app) doesn't send you back to step 1. */
function useStoredPage(id: string, last: number): [number, (page: number) => void] {
  const key = `tabooki-cook:${id}`
  const [page, setPage] = useState(() => {
    try {
      const stored = Number(sessionStorage.getItem(key))
      return Number.isInteger(stored) && stored >= 0 && stored <= last ? stored : 0
    } catch {
      return 0
    }
  })
  const update = (next: number) => {
    setPage(next)
    try {
      sessionStorage.setItem(key, String(next))
    } catch {
      // Private mode: just not remembered.
    }
  }
  return [page, update]
}

function Progress({ page, last }: { page: number; last: number }) {
  return (
    <div className="mt-3 flex gap-1" aria-hidden>
      {Array.from({ length: last }, (_, i) => (
        <span
          key={i}
          className={cn('h-2 flex-1 rounded-full border-[1.5px]', i < page ? 'border-outline bg-primary' : i === page ? 'border-outline bg-highlight' : 'border-transparent bg-muted')}
        />
      ))}
    </div>
  )
}

function IngredientChecklist({
  ingredients,
  factor,
  checked,
  onToggle,
  locale,
  large = false,
}: {
  ingredients: Ingredient[]
  factor: number
  checked: ReadonlySet<number>
  onToggle: (index: number) => void
  locale: string
  large?: boolean
}) {
  const { t } = useTranslation()
  // Each line's position in the whole list, which is what `checked` holds.
  const groups = groupIngredients(ingredients)
  const starts = groups.map((_, g) => groups.slice(0, g).reduce((sum, group) => sum + group.items.length, 0))
  return (
    <div className="flex flex-col gap-3">
      {groups.map((group, g) => (
        <div key={g}>
          {group.name && <h3 className="mb-1 text-sm font-semibold text-muted-foreground">{group.name}</h3>}
          <ul className="flex flex-col divide-y rounded-lg sticker bg-card">
            {group.items.map((ingredient, n) => {
              const i = starts[g] + n
              const line = scaleLine(ingredient.rawText, ingredient.quantity, factor, locale)
              const done = checked.has(i)
              return (
                <li key={i}>
                  <button
                    type="button"
                    role="checkbox"
                    aria-checked={done}
                    onClick={() => onToggle(i)}
                    className={cn('flex w-full items-center gap-3 px-3 text-left', large ? 'min-h-14 py-3 text-lg' : 'min-h-12 py-2')}
                  >
                    <CheckMark checked={done} className={large ? 'size-[1.625rem]' : undefined} />
                    <span className={cn('flex-1', done && 'text-muted-foreground line-through opacity-60', ingredient.optional && 'text-muted-foreground')}>{line.text}</span>
                    {!line.scaled && (
                      <span className="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums" title={t('cook.notScaled')}>
                        ×{new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(factor)}
                      </span>
                    )}
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </div>
  )
}

function TimerBar({ timers, now, onStop }: { timers: Timer[]; now: number; onStop: (id: number) => void }) {
  const { t } = useTranslation()
  return (
    <ul className="mt-3 flex flex-wrap gap-2" aria-label={t('cook.timers')}>
      {timers.map((timer) => (
        <li key={timer.id}>
          <button
            type="button"
            onClick={() => onStop(timer.id)}
            aria-label={timer.endsAt <= now ? t('cook.dismissTimer', { time: timer.label }) : t('cook.stopTimer', { time: timer.label })}
            className={cn(
              'inline-flex h-10 items-center gap-2 rounded-full border-2 border-outline px-3 font-extrabold tabular-nums shadow-[2px_2px_0_var(--shadow)]',
              timer.endsAt <= now ? 'animate-pulse bg-highlight text-highlight-foreground' : 'bg-card',
            )}
          >
            <TimerIcon className="size-4" aria-hidden />
            {timer.endsAt <= now ? t('cook.timeUp') : formatClock((timer.endsAt - now) / 1000)}
            <span className="text-xs font-normal opacity-75">{timer.label}</span>
            <X className="size-3.5 opacity-75" aria-hidden />
          </button>
        </li>
      ))}
    </ul>
  )
}

/** The end: rate it. From the menu the rating goes to that meal (and the recipe), else to the recipe. */
function Done({ recipe, onAgain, onClose }: { recipe: Recipe; onAgain: () => void; onClose: () => void }) {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const planId = params.get('plan')
  const entry = params.get('entry')
  const updateRecipe = useUpdateRecipe(recipe.id!)
  const updateMeal = useUpdateMeal()
  const [rating, setRating] = useState<number | null>(null)
  const saving = updateRecipe.isPending || updateMeal.isPending
  const failed = updateRecipe.isError || updateMeal.isError

  const rate = (value: number | null) => {
    if (value === null) return
    setRating(value)
    if (planId && entry) updateMeal.mutate({ planId, entry, rating: value })
    else updateRecipe.mutate({ rating: value })
  }

  return (
    <section className="flex flex-col items-center gap-4 pt-8 text-center" aria-labelledby="cook-done">
      <Eggs eggClassName="size-[5.5rem]" className="mb-2 gap-1.5" />
      <h1 id="cook-done" className="font-display text-5xl leading-tight font-normal text-brand">
        {t('cook.done')}
      </h1>
      <p className="text-[17px] text-muted-foreground">{t('cook.howWasIt')}</p>
      <StarRating value={rating ?? recipe.rating ?? null} onChange={rate} size="lg" clearable={false} label={t('cook.howWasIt')} />
      <p className="h-5 text-sm text-muted-foreground" role="status">
        {failed ? t('cook.rateError') : rating !== null && !saving ? t('cook.thanks') : ''}
      </p>
      <div className="mt-4 flex w-full flex-col gap-2">
        <Button className="h-14 text-lg" onClick={onClose}>
          {t('cook.backToRecipe')}
        </Button>
        <Button variant="ghost" onClick={onAgain}>
          {t('cook.again')}
        </Button>
      </div>
    </section>
  )
}
