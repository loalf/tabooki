import { CalendarPlus, ChefHat, ChevronRight, Clock, CornerDownRight, Lock, Moon, ShoppingCart, Shuffle, Sparkles, Sun, UtensilsCrossed } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router'
import { useMe } from '@/auth/useAuth'
import { type MealPlan, type MealPlanEntry, type Slot, useMealPlan, useUpdateMeal } from '@/api/plan'
import { useShoppingList } from '@/api/shopping'
import { Wordmark } from '@/components/brand'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatWeek, formatWeekday } from '@/components/plan/format'
import { mealQuery } from '@/components/plan/meal'
import { MealSheet } from '@/components/plan/MealSheet'
import { useTakeOffMenu } from '@/components/plan/useTakeOffMenu'
import { pageTitleClass } from '@/components/PageHeader'
import { StarRating } from '@/components/StarRating'
import { cn } from '@/lib/utils'
import { AccountSheet, Avatar } from '@/pages/SettingsPage'
import { addDays, mondayOf, parseIsoDate, today } from '@/lib/week'

const SLOTS: Slot[] = ['lunch', 'dinner']

/**
 * Home: what we're eating today, ready to cook, and the few things that can't wait (rate what you
 * ate, plan next week, the shopping). Everything else is one tab away.
 */
export function TodayPage() {
  const { t, i18n } = useTranslation()
  const { data: me } = useMe()
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  // The account sheet is in the address (?account), so "← Ajustes" on a settings page comes back to
  // it, and the phone's back button closes it rather than leaving Hoy.
  const account = params.has('account')
  const openAccount = () => setParams({ account: '' }, { state: { sheet: true } })
  const closeAccount = () => ((location.state as { sheet?: boolean } | null)?.sheet ? void navigate(-1) : setParams({}, { replace: true }))
  const now = today()
  const thisWeek = mondayOf(now)
  const plan = useMealPlan(thisWeek)
  const lastWeek = useMealPlan(addDays(thisWeek, -7))
  const nextWeek = useMealPlan(addDays(thisWeek, 7))
  const dateLabel = new Intl.DateTimeFormat(i18n.language, { weekday: 'long', day: 'numeric', month: 'long' }).format(parseIsoDate(now))

  const meals = SLOTS.map((slot) => ({ slot, entry: plan.data?.entries?.find((e) => e.date === now && e.slot === slot) }))
  // In the evening, dinner is what's next.
  const [evening] = useState(() => new Date().getHours() >= 16)
  const [first, second] = evening ? [meals[1], meals[0]] : meals

  return (
    <>
      <div className="mb-2 flex items-center justify-between gap-2">
        <Wordmark className="text-[1.875rem]" />
        <button type="button" onClick={openAccount} aria-label={t('settings.title')} aria-haspopup="dialog" className="rounded-full">
          <Avatar name={me?.displayName ?? me?.email ?? ''} />
        </button>
      </div>
      <p className="text-xs font-bold tracking-wider text-muted-foreground uppercase">{dateLabel}</p>
      <h1 className={cn('mb-4', pageTitleClass)}>{t('today.title')}</h1>

      <div className="flex flex-col gap-4">
        {plan.isPending ? (
          <div className="aspect-[16/10] animate-pulse rounded-lg bg-muted" aria-hidden />
        ) : !plan.data ? (
          <NoPlan week={thisWeek} current />
        ) : (
          <>
            <TodayMeal plan={plan.data} slot={first.slot} entry={first.entry} hero />
            <TodayMeal plan={plan.data} slot={second.slot} entry={second.entry} />
          </>
        )}

        <Pending plans={[lastWeek.data, plan.data]} nextWeek={nextWeek.isPending ? undefined : (nextWeek.data ?? null)} thisWeek={thisWeek} planned={Boolean(plan.data)} />
      </div>

      {account && <AccountSheet onClose={closeAccount} />}
    </>
  )
}

function TodayMeal({ plan, slot, entry, hero = false }: { plan: MealPlan; slot: Slot; entry?: MealPlanEntry; hero?: boolean }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(false)
  const SlotIcon = slot === 'lunch' ? Sun : Moon

  if (!entry) {
    return (
      <p className="flex items-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/60 px-3.5 py-3 text-sm font-semibold text-muted-foreground">
        <SlotIcon className="size-4" aria-hidden />
        {t('today.out', { meal: t(`recipe.meal.${slot}`) })}
      </p>
    )
  }

  const recipe = entry.recipe
  const leftoverOf = entry.leftoverOfId ? plan.entries?.find((e) => e.id === entry.leftoverOfId) : undefined
  const query = mealQuery(plan, entry)
  const meta = leftoverOf ? (
    <span className="inline-flex items-center gap-1 font-bold text-primary">
      <CornerDownRight className="size-3.5" aria-hidden />
      {t('plan.leftoversOf', { day: formatWeekday(leftoverOf.date!, i18n.language) })}
    </span>
  ) : (
    <span className="inline-flex flex-wrap items-center gap-x-2">
      {t('recipe.servingsCount', { count: entry.servings })}
      {recipe?.totalMinutes != null && (
        <span className="inline-flex items-center gap-1">
          · <Clock className="size-3.5" aria-hidden />
          {t('recipe.minutes', { count: recipe.totalMinutes })}
        </span>
      )}
    </span>
  )

  if (!hero) {
    return (
      <div className="flex items-center gap-3 rounded-lg sticker bg-card p-2">
        <Link to={recipe ? `/recipes/${recipe.id}${query}` : '/plan'} className="flex min-w-0 flex-1 items-center gap-3">
          <Thumbnail src={recipe?.source?.thumbnail} className="size-16 shrink-0 rounded-[11px] border-2 border-outline" />
          <span className="min-w-0 flex-1">
            <span className="flex items-center gap-1 text-xs font-semibold text-muted-foreground">
              <SlotIcon className="size-3" aria-hidden />
              {t(`recipe.meal.${slot}`)}
            </span>
            <span className="line-clamp-2 text-[15px] leading-snug font-bold">{recipe?.title ?? t('plan.noRecipe')}</span>
            <span className="block text-xs text-muted-foreground">{meta}</span>
          </span>
        </Link>
        {recipe && !leftoverOf ? (
          <Link
            to={`/recipes/${recipe.id}/cook${query}`}
            aria-label={`${t('cook.open')}: ${recipe.title}`}
            className="grid size-11 shrink-0 place-items-center rounded-md sticker-sm bg-primary text-primary-foreground"
          >
            <ChefHat className="size-5" aria-hidden />
          </Link>
        ) : (
          <button type="button" onClick={() => setOpen(true)} aria-label={t('plan.options')} className="grid size-11 shrink-0 place-items-center rounded-md sticker-sm bg-card">
            <Shuffle className="size-5" aria-hidden />
          </button>
        )}
        {open && <MealSheet plan={plan} entry={entry} onClose={() => setOpen(false)} />}
      </div>
    )
  }

  return (
    <article className="overflow-hidden rounded-lg sticker bg-card">
      <Link to={recipe ? `/recipes/${recipe.id}${query}` : '/plan'} className="relative block border-b-2 border-outline">
        <Thumbnail src={recipe?.source?.thumbnail} className="aspect-[2/1] w-full" />
        <span className="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full border-2 border-outline bg-highlight px-2.5 py-0.5 text-xs font-bold text-highlight-foreground">
          <SlotIcon className="size-3.5" aria-hidden />
          {t(`recipe.meal.${slot}`)}
        </span>
        {entry.locked && (
          <span className="absolute top-3 right-3 grid size-8 place-items-center rounded-full border-2 border-outline bg-card" aria-label={t('plan.sheet.locked')}>
            <Lock className="size-3.5" aria-hidden />
          </span>
        )}
      </Link>
      <div className="flex flex-col gap-3 px-3.5 pt-3 pb-3.5">
        <div>
          <h2 className="text-xl leading-tight font-extrabold text-balance">{recipe?.title ?? t('plan.noRecipe')}</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">{meta}</p>
        </div>
        <div className="flex gap-2.5">
          {recipe && !leftoverOf ? (
            <Link to={`/recipes/${recipe.id}/cook${query}`} className="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-md sticker-sm bg-primary px-4 font-bold text-primary-foreground">
              <ChefHat className="size-5" aria-hidden />
              {t('cook.open')}
            </Link>
          ) : (
            recipe && (
              <Link to={`/recipes/${recipe.id}`} className="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-md sticker-sm bg-primary px-4 font-bold text-primary-foreground">
                {t('plan.sheet.view')}
              </Link>
            )
          )}
          <button type="button" onClick={() => setOpen(true)} aria-haspopup="dialog" className={cn('inline-flex h-12 items-center justify-center gap-2 rounded-md sticker-sm bg-card px-4 font-bold', !recipe && 'flex-1')}>
            <Shuffle className="size-5" aria-hidden />
            {t('today.change')}
          </button>
        </div>
      </div>
      {open && <MealSheet plan={plan} entry={entry} onClose={() => setOpen(false)} />}
    </article>
  )
}

function NoPlan({ week, current = false }: { week: string; current?: boolean }) {
  const { t, i18n } = useTranslation()
  return (
    <div className="flex flex-col gap-3 rounded-lg border-2 border-outline bg-accent p-4 text-accent-foreground">
      <div className="flex gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-full border-2 border-outline bg-highlight text-highlight-foreground">
          <Sparkles className="size-4" aria-hidden />
        </span>
        <div>
          <p className="leading-snug font-bold">{current ? t('today.noPlanThisWeek') : t('today.noPlanNextWeek')}</p>
          <p className="text-[13px] opacity-80">{t('today.planHint', { week: formatWeek(week, i18n.language) })}</p>
        </div>
      </div>
      <Link to={current ? '/plan' : `/plan?week=${week}`} className="inline-flex h-11 items-center justify-center gap-2 rounded-md sticker-sm bg-primary font-bold text-primary-foreground">
        <CalendarPlus className="size-5" aria-hidden />
        {t('shopping.toPlan')}
      </Link>
    </div>
  )
}

/** Time-sensitive bits, each only when it applies. */
function Pending({
  plans,
  nextWeek,
  thisWeek,
  planned,
}: {
  plans: (MealPlan | null | undefined)[]
  nextWeek: MealPlan | null | undefined
  thisWeek: string
  planned: boolean
}) {
  const { t } = useTranslation()
  const now = today()
  // Meals of the last two days nobody has rated (leftovers are the same dish, so not those).
  const toRate = plans
    .flatMap((plan) => (plan?.entries ?? []).map((entry) => ({ plan: plan!, entry })))
    .filter(({ entry }) => entry.recipe && !entry.leftoverOfId && entry.rating == null && entry.date! < now && entry.date! >= addDays(now, -2))
    .sort((a, b) => (b.entry.date! + b.entry.slot).localeCompare(a.entry.date! + a.entry.slot))
    .slice(0, 2)
  // From Friday on, nudge to plan next week.
  const weekendish = [0, 5, 6].includes(parseIsoDate(now).getDay())
  const nudge = nextWeek === null && weekendish

  if (!toRate.length && !nudge && !planned) return null

  return (
    <section aria-labelledby="pending-title" className="mt-2 flex flex-col gap-2.5">
      <h2 id="pending-title" className="text-[17px] font-extrabold">
        {t('today.pending')}
      </h2>
      {nudge && <NoPlan week={addDays(thisWeek, 7)} />}
      {toRate.map(({ plan, entry }) => (
        <RateMeal key={entry.id} plan={plan} entry={entry} />
      ))}
      {planned && <ShoppingSummary week={thisWeek} />}
    </section>
  )
}

/**
 * Rate a meal of the last two days, or say it wasn't cooked: that takes it off the menu (with an
 * undo), so the card goes and the recipe doesn't count as cooked that day ("última vez").
 */
function RateMeal({ plan, entry }: { plan: MealPlan; entry: MealPlanEntry }) {
  const { t, i18n } = useTranslation()
  const update = useUpdateMeal()
  const { takeOff, isPending: removing } = useTakeOffMenu()
  const when = entry.date === addDays(today(), -1) ? t('today.yesterday') : formatWeekday(entry.date!, i18n.language)
  return (
    <div className={cn('flex items-center gap-3 rounded-lg sticker bg-card p-3', (update.isPending || removing) && 'opacity-60')}>
      <Thumbnail src={entry.recipe?.source?.thumbnail} className="size-12 shrink-0 rounded-[10px] border-2 border-outline" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-[13px] font-bold first-letter:uppercase">
          {when}, {t(`recipe.meal.${entry.slot}`).toLowerCase()}: {entry.recipe?.title}
        </p>
        <div className="-ml-1.5">
          <StarRating
            value={null}
            clearable={false}
            label={t('plan.howWasIt')}
            onChange={(rating) => rating && update.mutate({ planId: plan.id!, entry: entry.id!, rating })}
          />
        </div>
      </div>
      <button
        type="button"
        disabled={update.isPending || removing}
        onClick={() => takeOff(plan, entry)}
        aria-label={`${t('today.notCooked')}: ${entry.recipe?.title ?? ''}`}
        className="flex w-[62px] shrink-0 flex-col items-center justify-center gap-1 self-stretch rounded-md sticker-sm bg-card px-1 text-center text-[11px] leading-[1.1] font-bold text-balance"
      >
        <UtensilsCrossed className="size-[18px]" aria-hidden />
        {t('today.notCooked')}
      </button>
    </div>
  )
}

function ShoppingSummary({ week }: { week: string }) {
  const { t } = useTranslation()
  const { data: list } = useShoppingList({ week }, { live: false })
  const items = (list?.items ?? []).filter((i) => !i.staple)
  if (!list?.planned || !items.length) return null
  const done = items.filter((i) => i.checked).length
  const left = items.length - done

  return (
    <Link to="/shopping" className="flex items-center gap-3 rounded-lg sticker bg-card p-3.5">
      <span className="grid size-9 shrink-0 place-items-center rounded-[10px] border-2 border-outline bg-muted">
        <ShoppingCart className="size-[18px]" aria-hidden />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-bold">{left ? t('today.shoppingLeft', { count: left, total: items.length }) : t('today.shoppingDone')}</span>
        <span className="mt-1.5 block h-2 overflow-hidden rounded-full border-[1.5px] border-outline bg-muted" aria-hidden>
          <span className="block h-full bg-primary" style={{ width: `${(done / items.length) * 100}%` }} />
        </span>
      </span>
      <ChevronRight className="size-5 text-muted-foreground" aria-hidden />
    </Link>
  )
}

