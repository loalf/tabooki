import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useRecipes } from '@/api/library'
import { useMealPlan } from '@/api/plan'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { PlanSetup } from '@/components/plan/PlanSetup'
import { PlanWeek } from '@/components/plan/PlanWeek'
import { linkButtonClass } from '@/components/ui'
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
        <EmptyState
          title={t('plan.emptyLibraryTitle')}
          action={
            <Link to="/" className={linkButtonClass}>
              {t('plan.toLibrary')}
            </Link>
          }
        >
          {t('plan.emptyLibrary')}
        </EmptyState>
      ) : (
        // Keyed by week: the choices start over for each week.
        <PlanSetup key={week} week={week} previous={previous.data} />
      )}
    </>
  )
}
