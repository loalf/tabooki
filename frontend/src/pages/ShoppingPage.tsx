import { useTranslation } from 'react-i18next'
import { EmptyState, PageHeader } from '@/components/PageHeader'

export function ShoppingPage() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('shopping.title')} />
      <EmptyState>{t('shopping.empty')}</EmptyState>
    </>
  )
}
