import { useTranslation } from 'react-i18next'
import { Eggs } from '@/components/brand'

export function Splash() {
  const { t } = useTranslation()
  return (
    <div className="grid min-h-dvh place-items-center" role="status">
      <div className="flex flex-col items-center gap-4 text-muted-foreground">
        <Eggs wobble eggClassName="size-20" className="gap-1" />
        <span className="text-[15px]">{t('app.loading')}</span>
      </div>
    </div>
  )
}
