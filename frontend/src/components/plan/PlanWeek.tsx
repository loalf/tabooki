import { CheckCheck, ChevronDown, Hand } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { MealPlan, Slot } from '@/api/plan'
import { formatDay } from '@/components/plan/format'
import { MealRow } from '@/components/plan/MealRow'
import { cn } from '@/lib/utils'
import { parseIsoDate, today, weekDays } from '@/lib/week'

const SLOTS: Slot[] = ['lunch', 'dinner']

/** A planned week: the whole week in one strip, then day by day (days already eaten folded away). */
export function PlanWeek({ plan }: { plan: MealPlan }) {
  const { t, i18n } = useTranslation()
  const now = today()
  const days = weekDays(plan.weekStart!)
  const past = days.filter((d) => d < now)
  const [showPast, setShowPast] = useState(false)
  const pastMeals = (plan.entries ?? []).filter((e) => e.date! < now).length
  // Only fold when it's the current week and there's something left to see.
  const fold = past.length > 0 && past.length < 7 && !showPast
  const visible = fold ? days.filter((d) => d >= now) : days
  const short = new Intl.DateTimeFormat(i18n.language, { weekday: 'short', day: 'numeric' })

  return (
    <div className="flex flex-col gap-5">
      <DayStrip plan={plan} onPick={(date) => {
        if (date < now) setShowPast(true)
        // After the folded days open up.
        requestAnimationFrame(() => document.getElementById(`day-${date}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
      }} />

      {fold && (
        <button type="button" onClick={() => setShowPast(true)} className="flex items-center gap-2.5 rounded-md bg-muted px-3 py-2.5 text-left text-sm font-semibold text-muted-foreground">
          <CheckCheck className="size-4 shrink-0 text-primary" aria-hidden />
          <span className="flex-1 first-letter:uppercase">
            {t('plan.pastDays', {
              range: past.length === 1 ? short.format(parseIsoDate(past[0])) : `${short.format(parseIsoDate(past[0]))} – ${short.format(parseIsoDate(past.at(-1)!))}`,
              count: pastMeals,
            })}
          </span>
          <ChevronDown className="size-4 shrink-0" aria-hidden />
        </button>
      )}

      {visible.map((date) => (
        <section key={date} id={`day-${date}`} aria-labelledby={`day-title-${date}`} className={cn('scroll-mt-4', date < now && 'opacity-70')}>
          <h2 id={`day-title-${date}`} className="mb-2 flex items-center gap-2.5 text-lg font-extrabold capitalize">
            {formatDay(date, i18n.language)}
            {date === now && (
              <span className="-rotate-[5deg] rounded-full border-2 border-outline bg-highlight px-2.5 py-0.5 text-[13px] font-extrabold text-highlight-foreground shadow-[2px_2px_0_var(--shadow)]">
                {t('plan.today')}
              </span>
            )}
          </h2>
          <ul className="flex flex-col gap-2">
            {SLOTS.map((slot) => (
              <MealRow key={slot} plan={plan} date={date} slot={slot} entry={plan.entries?.find((e) => e.date === date && e.slot === slot)} />
            ))}
          </ul>
        </section>
      ))}

      <p className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
        <Hand className="size-3.5" aria-hidden />
        {t('plan.tapHint')}
      </p>
    </div>
  )
}

/** Seven days at a glance: a dot per planned meal (hollow if it's eaten out), today in yolk. */
function DayStrip({ plan, onPick }: { plan: MealPlan; onPick: (date: string) => void }) {
  const { i18n } = useTranslation()
  const now = today()
  const weekday = new Intl.DateTimeFormat(i18n.language, { weekday: 'narrow' })
  const full = new Intl.DateTimeFormat(i18n.language, { weekday: 'long', day: 'numeric' })
  return (
    <ol className="grid grid-cols-7 gap-1.5">
      {weekDays(plan.weekStart!).map((date) => {
        const meals = SLOTS.map((slot) => plan.entries?.find((e) => e.date === date && e.slot === slot))
        return (
          <li key={date}>
            <button
              type="button"
              onClick={() => onPick(date)}
              aria-label={full.format(parseIsoDate(date))}
              aria-current={date === now ? 'date' : undefined}
              className={cn(
                'flex w-full flex-col items-center gap-0.5 rounded-[12px] border-2 border-outline pt-1.5 pb-2',
                date === now ? 'bg-highlight text-highlight-foreground' : 'bg-card',
                date < now && 'opacity-60',
              )}
            >
              <span className={cn('text-[11px] font-bold uppercase', date !== now && 'text-muted-foreground')}>{weekday.format(parseIsoDate(date))}</span>
              <span className="text-[17px] leading-tight font-extrabold tabular-nums">{parseIsoDate(date).getDate()}</span>
              <span className="flex gap-[3px]" aria-hidden>
                {meals.map((meal, i) => (
                  <span key={i} className={cn('size-1.5 rounded-full', meal ? 'bg-primary' : 'border-[1.5px] border-muted-foreground/60')} />
                ))}
              </span>
            </button>
          </li>
        )
      })}
    </ol>
  )
}
