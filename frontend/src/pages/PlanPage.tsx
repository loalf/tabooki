import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useRecipes } from '@/api/library'
import { useMealPlan } from '@/api/plan'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { PlanSetup } from '@/components/plan/PlanSetup'
import { PlanWeek } from '@/components/plan/PlanWeek'
import { WeekNav } from '@/components/WeekNav'
import { useWeekParam } from '@/lib/useWeekParam'
import { addDays } from '@/lib/week'

/** The week's menu: browse weeks (past ones are the history), plan one, or change it. */
export function PlanPage() {
  const { t } = useTranslation()
  const nav = useWeekParam()
  const { week } = nav
  const plan = useMealPlan(week)
  const previous = useMealPlan(addDays(week, -7))
  const library = useRecipes({ q: '', tags: [], favorite: false })

  return (
    <>
      <PageHeader title={t('plan.title')} />
      <WeekNav {...nav} />

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
