import { CalendarCheck, CalendarPlus, Check, ChevronLeft, ChevronRight, Moon, Sparkles, Sun } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { type Recipe, useUpdateRecipe } from '@/api/library'
import { type Slot, useAddMeal, useMealPlan, useUpdateMeal } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { formatDay, formatWeek } from '@/components/plan/format'
import { Sheet } from '@/components/Sheet'
import { Button } from '@/components/ui'
import { cn } from '@/lib/utils'
import { addDays, mondayOf, parseIsoDate, today, weekDays } from '@/lib/week'

const SLOTS: Slot[] = ['lunch', 'dinner']

/**
 * From a recipe to the menu, the loop the recipe page used to miss. Either leave it for the next
 * menu (the planner fits it in, least effort) or put it on a given day and meal.
 */
export function AddToMenuSheet({ recipe, onClose }: { recipe: Recipe; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const now = today()
  const thisWeek = mondayOf(now)
  const [week, setWeek] = useState(thisWeek)
  const plan = useMealPlan(week)
  const days = weekDays(week)
  const [day, setDay] = useState<string | null>(null)
  const selectedDay = day && days.includes(day) && day >= now ? day : (days.find((d) => d >= now) ?? days[0])
  const entryAt = (slot: Slot) => plan.data?.entries?.find((e) => e.date === selectedDay && e.slot === slot)
  const suits = (s: Slot) => !recipe.mealTypes?.length || recipe.mealTypes.includes(s)
  const preferred = SLOTS.find((s) => suits(s) && !entryAt(s)) ?? SLOTS.find(suits) ?? 'dinner'
  const [slotChoice, setSlot] = useState<Slot | null>(null)
  const slot = slotChoice ?? preferred
  const updateRecipe = useUpdateRecipe(recipe.id!)
  const add = useAddMeal()
  const update = useUpdateMeal()
  const [done, setDone] = useState<{ date: string; slot: Slot } | null>(null)
  const busy = add.isPending || update.isPending
  const already = entryAt(slot)?.recipe?.id === recipe.id

  const confirm = () => {
    const planId = plan.data?.id
    if (!planId) return
    const existing = entryAt(slot)
    const finish = () => setDone({ date: selectedDay, slot })
    if (existing) {
      update.mutate({ planId, entry: existing.id!, recipe: recipe.id! }, { onSuccess: finish })
      return
    }
    add.mutate(
      { planId, date: selectedDay, slot },
      {
        onSuccess: (next) => {
          const created = next.entries?.find((e) => e.date === selectedDay && e.slot === slot)
          if (created) update.mutate({ planId, entry: created.id!, recipe: recipe.id! }, { onSuccess: finish })
        },
      },
    )
  }

  if (done) {
    return (
      <Sheet title={t('menu.added')} onClose={onClose}>
        <div className="flex flex-col gap-4">
          <p className="flex items-center gap-2 text-[15px]">
            <CalendarCheck className="size-5 shrink-0 text-primary" aria-hidden />
            {t('menu.addedTo', { day: formatDay(done.date, i18n.language), meal: t(`recipe.meal.${done.slot}`).toLowerCase() })}
          </p>
          <div className="flex gap-2">
            <Link to={week === thisWeek ? '/plan' : `/plan?week=${week}`} className="inline-flex h-12 flex-1 items-center justify-center rounded-md sticker-sm bg-card font-bold">
              {t('menu.seeMenu')}
            </Link>
            <Button className="flex-1" onClick={onClose}>
              {t('menu.ok')}
            </Button>
          </div>
        </div>
      </Sheet>
    )
  }

  return (
    <Sheet title={t('menu.title')} onClose={onClose} hideTitle>
      <div className={cn('flex flex-col gap-4', busy && 'opacity-70')}>
        <header className="flex items-center gap-3">
          <Thumbnail src={recipe.source?.thumbnail} className="size-11 shrink-0 rounded-[10px] border-2 border-outline" />
          <div className="min-w-0">
            <p className="text-[1.375rem] leading-tight font-extrabold">{t('menu.title')}</p>
            <p className="truncate text-[13px] text-muted-foreground">{recipe.title}</p>
          </div>
        </header>

        <button
          type="button"
          disabled={updateRecipe.isPending}
          aria-pressed={recipe.planNext}
          onClick={() => updateRecipe.mutate({ planNext: !recipe.planNext })}
          className="flex items-center gap-3 rounded-lg border-2 border-outline bg-accent p-3.5 text-left text-accent-foreground disabled:opacity-60"
        >
          <span className={cn('grid size-9 shrink-0 place-items-center rounded-full border-2 border-outline', recipe.planNext ? 'bg-primary text-primary-foreground' : 'bg-highlight text-highlight-foreground')}>
            {recipe.planNext ? <Check className="size-4" strokeWidth={3} aria-hidden /> : <Sparkles className="size-4" aria-hidden />}
          </span>
          <span className="min-w-0 flex-1">
            <span className="block font-bold">{recipe.planNext ? t('menu.queued') : t('menu.queue')}</span>
            <span className="block text-xs leading-snug opacity-80">{recipe.planNext ? t('menu.queuedHint') : t('menu.queueHint')}</span>
          </span>
        </button>

        <div className="flex items-center gap-2.5 text-xs font-bold text-muted-foreground" aria-hidden>
          <span className="h-0.5 flex-1 bg-border" />
          {t('menu.orPickDay')}
          <span className="h-0.5 flex-1 bg-border" />
        </div>

        <div className="flex items-center justify-between">
          <button type="button" className="grid size-9 place-items-center text-muted-foreground disabled:opacity-30" disabled={week <= thisWeek} onClick={() => setWeek(addDays(week, -7))} aria-label={t('plan.previousWeek')}>
            <ChevronLeft className="size-5" aria-hidden />
          </button>
          <span className="text-sm font-bold">
            {week === thisWeek ? t('menu.thisWeek') : week === addDays(thisWeek, 7) ? t('menu.nextWeek') : ''} {week === thisWeek || week === addDays(thisWeek, 7) ? '· ' : ''}
            {formatWeek(week, i18n.language)}
          </span>
          <button type="button" className="grid size-9 place-items-center text-muted-foreground" onClick={() => setWeek(addDays(week, 7))} aria-label={t('plan.nextWeek')}>
            <ChevronRight className="size-5" aria-hidden />
          </button>
        </div>

        <ol className="grid grid-cols-7 gap-1.5">
          {days.map((date) => {
            const past = date < now
            const on = date === selectedDay
            return (
              <li key={date}>
                <button
                  type="button"
                  disabled={past}
                  onClick={() => {
                    setDay(date)
                    setSlot(null)
                  }}
                  aria-pressed={on}
                  aria-label={formatDay(date, i18n.language)}
                  className={cn('flex w-full flex-col items-center rounded-[12px] border-2 border-outline py-1.5 disabled:opacity-35', on ? 'bg-highlight text-highlight-foreground' : 'bg-card')}
                >
                  <span className={cn('text-[11px] font-bold uppercase', !on && 'text-muted-foreground')}>
                    {new Intl.DateTimeFormat(i18n.language, { weekday: 'narrow' }).format(parseIsoDate(date))}
                  </span>
                  <span className="text-[17px] font-extrabold tabular-nums">{parseIsoDate(date).getDate()}</span>
                </button>
              </li>
            )
          })}
        </ol>

        {plan.isPending ? (
          <div className="h-32 animate-pulse rounded-lg bg-muted" aria-hidden />
        ) : !plan.data ? (
          <div className="rounded-lg border-2 border-dashed border-muted-foreground/60 p-4 text-sm text-muted-foreground">
            <p>{t('menu.noPlan')}</p>
            <Link to={week === thisWeek ? '/plan' : `/plan?week=${week}`} className="mt-2 inline-block font-bold text-primary underline-offset-2 hover:underline">
              {t('shopping.toPlan')}
            </Link>
          </div>
        ) : (
          <>
            <div className="flex flex-col gap-2" role="radiogroup" aria-label={t('menu.meal')}>
              {SLOTS.map((s) => {
                const entry = entryAt(s)
                const on = s === slot
                const Icon = s === 'lunch' ? Sun : Moon
                return (
                  <button
                    key={s}
                    type="button"
                    role="radio"
                    aria-checked={on}
                    onClick={() => setSlot(s)}
                    className={cn('flex items-center gap-3 rounded-md border-2 border-outline p-3 text-left', on ? 'bg-card shadow-[3px_3px_0_var(--shadow)]' : 'border-dashed bg-background')}
                  >
                    <Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="min-w-0 flex-1">
                      <span className="block font-bold">{t(`recipe.meal.${s}`)}</span>
                      <span className={cn('block truncate text-xs font-semibold', entry ? 'text-muted-foreground' : 'text-primary')}>
                        {entry?.recipe?.id === recipe.id
                          ? t('menu.already')
                          : entry?.recipe
                            ? t('menu.replaces', { title: entry.recipe.title })
                            : entry
                              ? t('menu.emptyMeal')
                              : t('menu.free')}
                      </span>
                    </span>
                    <span className={cn('grid size-[22px] shrink-0 place-items-center rounded-full border-2 border-outline', on ? 'bg-primary text-primary-foreground' : 'bg-card')} aria-hidden>
                      {on && <Check className="size-3" strokeWidth={3} />}
                    </span>
                  </button>
                )
              })}
            </div>
            <Button className="w-full" onClick={confirm} disabled={busy || already}>
              <CalendarPlus className="size-5" aria-hidden />
              {t('menu.addTo', { day: formatDay(selectedDay, i18n.language), meal: t(`recipe.meal.${slot}`).toLowerCase() })}
            </Button>
            {(add.isError || update.isError) && <p className="text-sm text-destructive">{t('plan.error')}</p>}
          </>
        )}
      </div>
    </Sheet>
  )
}
