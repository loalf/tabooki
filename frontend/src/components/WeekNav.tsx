import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatWeek } from '@/components/plan/format'
import { Button } from '@/components/ui'
import type { useWeekParam } from '@/lib/useWeekParam'
import { addDays } from '@/lib/week'

/** ‹ 23–29 nov › with a way back to this week. */
export function WeekNav({ week, thisWeek, go }: ReturnType<typeof useWeekParam>) {
  const { t, i18n } = useTranslation()
  return (
    <nav className="mb-5 flex items-center gap-2" aria-label={t('plan.weeks')}>
      <Button variant="secondary" size="icon" onClick={() => go(addDays(week, -7))} aria-label={t('plan.previousWeek')}>
        <ChevronLeft className="size-5" aria-hidden />
      </Button>
      <div className="flex-1 text-center">
        <p className="font-medium">{formatWeek(week, i18n.language)}</p>
        {week !== thisWeek && (
          <button type="button" onClick={() => go(thisWeek)} className="text-xs font-medium text-primary hover:underline">
            {t('plan.backToThisWeek')}
          </button>
        )}
      </div>
      <Button variant="secondary" size="icon" onClick={() => go(addDays(week, 7))} aria-label={t('plan.nextWeek')}>
        <ChevronRight className="size-5" aria-hidden />
      </Button>
    </nav>
  )
}
