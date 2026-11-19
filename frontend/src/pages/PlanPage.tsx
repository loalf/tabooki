import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { useRecipes } from '@/api/library'
import { useMealPlan } from '@/api/plan'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { formatWeek } from '@/components/plan/format'
import { PlanSetup } from '@/components/plan/PlanSetup'
import { PlanWeek } from '@/components/plan/PlanWeek'
import { Button } from '@/components/ui'
import { addDays, isValidIsoDate, mondayOf, today } from '@/lib/week'

/** The week's menu: browse weeks (past ones are the history), plan one, or change it. */
export function PlanPage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const requested = params.get('week')
  const thisWeek = mondayOf(today())
  const week = isValidIsoDate(requested) ? mondayOf(requested) : thisWeek
  const plan = useMealPlan(week)
  const previous = useMealPlan(addDays(week, -7))
  const library = useRecipes({ q: '', tags: [], favorite: false })

  const go = (monday: string) => setParams(monday === thisWeek ? {} : { week: monday }, { replace: true })

  return (
    <>
      <PageHeader title={t('plan.title')} />
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

      {plan.isPending || previous.isPending ? null : plan.isError ? (
        <EmptyState>{t('plan.loadError')}</EmptyState>
      ) : plan.data ? (
        <PlanWeek plan={plan.data} />
      ) : library.data?.length === 0 ? (
        <EmptyState>
          {t('plan.emptyLibrary')}{' '}
          <Link to="/" className="font-medium text-primary underline-offset-4 hover:underline">
            {t('plan.toLibrary')}
          </Link>
        </EmptyState>
      ) : (
        // Keyed by week: the choices start over for each week.
        <PlanSetup key={week} week={week} previous={previous.data} />
      )}
    </>
  )
}
