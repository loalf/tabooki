import { useTranslation } from 'react-i18next'
import { EmptyState, PageHeader } from '@/components/PageHeader'

export function LibraryPage() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('library.title')} />
      <EmptyState>{t('library.empty')}</EmptyState>
    </>
  )
}
