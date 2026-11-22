import { RefreshCw, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type MealPlan, type Slot, useDeleteMealPlan, useRegenerateMealPlan } from '@/api/plan'
import { formatDay } from '@/components/plan/format'
import { MealRow } from '@/components/plan/MealRow'
import { Button } from '@/components/ui'
import { cn } from '@/lib/utils'
import { today, weekDays } from '@/lib/week'

const SLOTS: Slot[] = ['lunch', 'dinner']

/** A planned week, day by day. */
export function PlanWeek({ plan }: { plan: MealPlan }) {
  const { t, i18n } = useTranslation()
  const regenerate = useRegenerateMealPlan()
  const remove = useDeleteMealPlan()
  const [confirming, setConfirming] = useState(false)
  const unlocked = plan.entries?.filter((e) => !e.locked).length ?? 0
  const now = today()

  useEffect(() => {
    if (!confirming) return
    const timer = setTimeout(() => setConfirming(false), 4000)
    return () => clearTimeout(timer)
  }, [confirming])

  return (
    <div className="flex flex-col gap-5">
      <div className="flex gap-2">
        <Button variant="secondary" className="flex-1" disabled={regenerate.isPending || unlocked === 0} onClick={() => regenerate.mutate(plan.id!)}>
          <RefreshCw className={cn('size-4', regenerate.isPending && 'animate-spin')} aria-hidden />
          {t('plan.regenerate')}
        </Button>
        <Button
          variant={confirming ? 'danger' : 'secondary'}
          size={confirming ? 'md' : 'icon'}
          className={cn(!confirming && 'size-12')}
          disabled={remove.isPending}
          onClick={() => (confirming ? remove.mutate(plan) : setConfirming(true))}
          aria-label={confirming ? undefined : t('plan.delete')}
        >
          <Trash2 className="size-4" aria-hidden />
          {confirming && t('common.confirmDelete')}
        </Button>
      </div>
      {unlocked > 0 && <p className="-mt-3 text-xs text-muted-foreground">{t('plan.regenerateHint')}</p>}

      {weekDays(plan.weekStart!).map((date) => (
        <section key={date} aria-labelledby={`day-${date}`} className={cn(date < now && 'opacity-70')}>
          <h2 id={`day-${date}`} className="mb-2 flex items-center gap-2.5 text-lg font-extrabold capitalize">
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
    </div>
  )
}
