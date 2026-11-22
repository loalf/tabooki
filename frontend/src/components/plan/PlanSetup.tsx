import { Check, ChevronRight, Minus, Plus, Salad } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useTasteProfile } from '@/api/taste'
import { useMe } from '@/auth/useAuth'
import { type MealPlan, type Slot, type SlotChoice, useCreateMealPlan } from '@/api/plan'
import { Button, CheckMark } from '@/components/ui'
import { formatDay } from '@/components/plan/format'
import { cn } from '@/lib/utils'
import { addDays, parseIsoDate, weekDays } from '@/lib/week'

const SLOTS: Slot[] = ['lunch', 'dinner']
const TIME_LIMITS = [null, 30, 45, 60] as const

/** "slot@weekday" keys (0 = Monday) of the meals a plan has, to suggest the same next week. */
function pattern(plan: MealPlan | null | undefined): Set<string> | null {
  if (!plan?.entries?.length || !plan.weekStart) return null
  const monday = parseIsoDate(plan.weekStart).getTime()
  return new Set(
    plan.entries.map((e) => `${e.slot}@${Math.round((parseIsoDate(e.date!).getTime() - monday) / 86_400_000)}`),
  )
}

/** Choosing a week's meals before planning it. Starts from last week's choices, if there was a plan. */
export function PlanSetup({ week, previous }: { week: string; previous?: MealPlan | null }) {
  const { t, i18n } = useTranslation()
  const { data: me } = useMe()
  const create = useCreateMealPlan()
  const last = pattern(previous)
  const [chosen, setChosen] = useState<Set<string>>(
    () => last ?? new Set(weekDays(week).flatMap((_, day) => SLOTS.map((slot) => `${slot}@${day}`))),
  )
  const [servings, setServings] = useState(previous?.entries?.[0]?.servings ?? me?.defaultServings ?? 4)
  const [leftovers, setLeftovers] = useState(previous?.leftovers ?? false)
  const [maxMinutes, setMaxMinutes] = useState<number | null>(previous?.weekdayMaxMinutes ?? null)

  const toggle = (key: string) =>
    setChosen((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const slots: SlotChoice[] = [...chosen].map((key) => {
      const [slot, day] = key.split('@')
      return { date: addDays(week, Number(day)), slot: slot as Slot, servings }
    })
    create.mutate({ weekStart: week, slots, leftovers, weekdayMaxMinutes: maxMinutes })
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-5">
      <TasteNudge />
      <section className="rounded-lg sticker bg-card p-4">
        <h2 className="text-[17px] font-extrabold">{t('plan.setup.title')}</h2>
        <p className="mt-1 mb-3 text-sm text-muted-foreground">{t('plan.setup.subtitle')}</p>
        <table className="w-full table-fixed text-sm">
          <thead>
            <tr className="text-muted-foreground">
              <th className="w-1/3 pb-2 text-left font-normal" />
              {SLOTS.map((slot) => (
                <th key={slot} className="pb-2 font-medium">
                  {t(`recipe.meal.${slot}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {weekDays(week).map((date, day) => (
              <tr key={date}>
                <th scope="row" className="py-1 text-left font-normal capitalize">
                  {formatDay(date, i18n.language)}
                </th>
                {SLOTS.map((slot) => {
                  const key = `${slot}@${day}`
                  const on = chosen.has(key)
                  return (
                    <td key={slot} className="px-1 py-1">
                      <button
                        type="button"
                        onClick={() => toggle(key)}
                        aria-pressed={on}
                        aria-label={`${formatDay(date, i18n.language)}, ${t(`recipe.meal.${slot}`)}`}
                        className={cn(
                          'grid h-10 w-full place-items-center rounded-sm border-2 text-sm font-semibold transition-colors',
                          on ? 'border-outline bg-accent text-accent-foreground' : 'border-dashed border-muted-foreground/60 text-muted-foreground',
                        )}
                      >
                        {on ? <Check className="size-[18px]" strokeWidth={2.5} aria-hidden /> : t('plan.setup.out')}
                      </button>
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section className="flex flex-col gap-4 rounded-lg sticker bg-card p-4">
        <div className="flex items-center justify-between gap-3">
          <span className="text-[15px] font-bold" id="servings-label">
            {t('plan.setup.servings')}
          </span>
          <div className="flex items-center gap-2" role="group" aria-labelledby="servings-label">
            <Button variant="secondary" size="icon" onClick={() => setServings(Math.max(1, servings - 1))} aria-label={t('plan.fewer')}>
              <Minus className="size-4" aria-hidden />
            </Button>
            <span className="w-8 text-center text-lg font-extrabold tabular-nums" aria-live="polite">
              {servings}
            </span>
            <Button variant="secondary" size="icon" onClick={() => setServings(Math.min(50, servings + 1))} aria-label={t('plan.more')}>
              <Plus className="size-4" aria-hidden />
            </Button>
          </div>
        </div>

        <label className="flex items-start gap-3 text-sm">
          <input type="checkbox" checked={leftovers} onChange={(e) => setLeftovers(e.target.checked)} className="peer sr-only" />
          <CheckMark checked={leftovers} className="mt-0.5 peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2" />
          <span>
            <span className="text-[15px] font-bold">{t('plan.setup.leftovers')}</span>
            <span className="block text-muted-foreground">{t('plan.setup.leftoversHint')}</span>
          </span>
        </label>

        <fieldset>
          <legend className="mb-2 text-[15px] font-bold">{t('plan.setup.weekdayTime')}</legend>
          <div className="grid grid-cols-4 gap-2">
            {TIME_LIMITS.map((limit) => (
              <button
                key={limit ?? 'none'}
                type="button"
                onClick={() => setMaxMinutes(limit)}
                aria-pressed={maxMinutes === limit}
                className={cn(
                  'h-10 rounded-sm border-2 border-outline text-sm',
                  maxMinutes === limit ? 'bg-highlight font-extrabold text-highlight-foreground' : 'bg-card font-semibold',
                )}
              >
                {limit === null ? t('plan.setup.noLimit') : t('recipe.minutes', { count: limit })}
              </button>
            ))}
          </div>
        </fieldset>
      </section>

      {create.isError && <p className="text-sm text-destructive">{t('plan.error')}</p>}
      <Button type="submit" disabled={chosen.size === 0 || create.isPending}>
        {create.isPending ? t('plan.setup.creating') : t('plan.setup.create', { count: chosen.size })}
      </Button>
    </form>
  )
}

/** Until the household has said what it likes: two minutes of questions make every menu fit better. */
function TasteNudge() {
  const { t } = useTranslation()
  const { data: profile, isPending } = useTasteProfile()
  if (isPending || profile) return null
  return (
    <Link to="/settings/taste" className="flex items-center gap-3 rounded-lg sticker bg-accent p-4 text-accent-foreground">
      <Salad className="size-6 shrink-0" aria-hidden />
      <span className="flex-1">
        <span className="block font-extrabold">{t('taste.nudge.title')}</span>
        <span className="block text-sm">{t('taste.nudge.text')}</span>
      </span>
      <ChevronRight className="size-5" aria-hidden />
    </Link>
  )
}
