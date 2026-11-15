import { useTranslation } from 'react-i18next'
import { EmptyState, PageHeader } from '@/components/PageHeader'

export function PlanPage() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('plan.title')} />
      <EmptyState>{t('plan.empty')}</EmptyState>
    </>
  )
}
