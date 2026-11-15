import { useTranslation } from 'react-i18next'

export function Splash() {
  const { t } = useTranslation()
  return (
    <div className="grid min-h-dvh place-items-center" role="status">
      <div className="flex flex-col items-center gap-3 text-muted-foreground">
        <img src="/logo.svg" alt="" className="size-16 animate-pulse" />
        <span className="text-sm">{t('app.loading')}</span>
      </div>
    </div>
  )
}
