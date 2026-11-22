import { Ellipsis, RefreshCw, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useRecipes } from '@/api/library'
import { type MealPlan, useDeleteMealPlan, useMealPlan, useRegenerateMealPlan } from '@/api/plan'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { PlanSetup } from '@/components/plan/PlanSetup'
import { PlanWeek } from '@/components/plan/PlanWeek'
import { SheetRowContent, Sheet, sheetRowClass } from '@/components/Sheet'
import { linkButtonClass } from '@/components/ui'
import { WeekNav } from '@/components/WeekNav'
import { cn } from '@/lib/utils'
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
  const [actions, setActions] = useState(false)

  return (
    <>
      <div className="mb-4 flex items-center justify-between gap-2">
        <h1 className={pageTitleClass}>{t('plan.title')}</h1>
        {plan.data && (
          <button
            type="button"
            onClick={() => setActions(true)}
            aria-label={t('plan.options')}
            aria-haspopup="dialog"
            className="grid size-11 place-items-center rounded-full sticker-sm bg-card"
          >
            <Ellipsis className="size-5" aria-hidden />
          </button>
        )}
      </div>
      <WeekNav {...nav} />

      {plan.isPending || previous.isPending ? null : plan.isError ? (
        <EmptyState>{t('plan.loadError')}</EmptyState>
      ) : plan.data ? (
        <PlanWeek plan={plan.data} />
      ) : library.data?.length === 0 ? (
        <EmptyState
          title={t('plan.emptyLibraryTitle')}
          action={
            <Link to="/recipes" className={linkButtonClass}>
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

      {actions && plan.data && <PlanActions plan={plan.data} onClose={() => setActions(false)} />}
    </>
  )
}

/** The rare, sweeping actions on a whole week, out of the way of everyday ones. */
function PlanActions({ plan, onClose }: { plan: MealPlan; onClose: () => void }) {
  const { t } = useTranslation()
  const regenerate = useRegenerateMealPlan()
  const remove = useDeleteMealPlan()
  const [confirming, setConfirming] = useState(false)
  const unlocked = plan.entries?.filter((e) => !e.locked).length ?? 0

  useEffect(() => {
    if (!confirming) return
    const timer = setTimeout(() => setConfirming(false), 4000)
    return () => clearTimeout(timer)
  }, [confirming])

  return (
    <Sheet title={t('plan.actions')} onClose={onClose}>
      <div className="flex flex-col divide-y">
        <button type="button" className={sheetRowClass} disabled={regenerate.isPending || unlocked === 0} onClick={() => regenerate.mutate(plan.id!, { onSuccess: onClose })}>
          <SheetRowContent icon={<RefreshCw className={cn(regenerate.isPending && 'animate-spin')} />} title={t('plan.regenerate')} hint={t('plan.regenerateHint')} />
        </button>
        <button
          type="button"
          className={cn(sheetRowClass, 'text-destructive')}
          disabled={remove.isPending}
          onClick={() => (confirming ? remove.mutate(plan, { onSuccess: onClose }) : setConfirming(true))}
        >
          <SheetRowContent icon={<Trash2 />} title={confirming ? t('common.confirmDelete') : t('plan.delete')} hint={t('plan.deleteHint')} />
        </button>
      </div>
    </Sheet>
  )
}
