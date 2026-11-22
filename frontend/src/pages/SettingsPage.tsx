import { ChevronRight, LogOut, Receipt } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useLogout, useMe, useUpdateMe } from '@/auth/useAuth'
import { ChangePassword } from '@/components/ChangePassword'
import { PageHeader } from '@/components/PageHeader'
import { ShareSettings } from '@/components/ShareSettings'
import { Sheet } from '@/components/Sheet'
import { LOCALES, type Locale } from '@/i18n'
import { cn } from '@/lib/utils'

/** Deep links (and the share tip in "Añadir receta") land here; day to day it's the account sheet. */
export function SettingsPage() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('settings.title')} />
      <SettingsContent />
    </>
  )
}

/** Opened from the avatar on Hoy: settings are rare, so they don't take a tab. */
export function AccountSheet({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  return (
    <Sheet title={t('settings.title')} onClose={onClose} hideTitle>
      <SettingsContent />
    </Sheet>
  )
}

export function Avatar({ name, className }: { name: string; className?: string }) {
  return (
    <span
      className={cn('grid size-10 shrink-0 place-items-center rounded-full border-2 border-outline bg-accent text-base font-extrabold text-accent-foreground', className)}
      aria-hidden
    >
      {name.trim().charAt(0).toLocaleUpperCase() || '·'}
    </span>
  )
}

function SettingsContent() {
  const { t, i18n } = useTranslation()
  const { data: me } = useMe()
  const updateMe = useUpdateMe()
  const logout = useLogout()

  if (!me) return null

  const changeLocale = (locale: Locale) => {
    void i18n.changeLanguage(locale)
    updateMe.mutate({ locale })
  }

  return (
    <section className="flex flex-col gap-5">
      <div className="flex items-center gap-3">
        <Avatar name={me.displayName ?? me.email ?? ''} className="size-13 text-xl" />
        <div className="min-w-0">
          <p className="truncate text-[17px] font-extrabold">{me.displayName}</p>
          <p className="truncate text-sm text-muted-foreground">{me.email}</p>
          <p className="text-sm text-muted-foreground">
            {t('settings.household')}: {me.householdName}
          </p>
        </div>
      </div>

      <div className="flex items-center justify-between gap-3">
        <span className="font-extrabold" id="language-label">
          {t('settings.language')}
        </span>
        <div className="flex gap-1 rounded-md border-2 border-outline bg-muted p-1" role="group" aria-labelledby="language-label">
          {LOCALES.map((locale) => (
            <button
              key={locale}
              type="button"
              onClick={() => changeLocale(locale)}
              aria-pressed={me.locale === locale}
              className={cn(
                'h-9 rounded-sm px-3.5 text-sm font-bold',
                me.locale === locale ? 'border-2 border-outline bg-card' : 'text-muted-foreground',
              )}
            >
              {t(`languages.${locale}`)}
            </button>
          ))}
        </div>
      </div>

      <ShareSettings />

      <ChangePassword />

      <Link to="/settings/costs" className="flex items-center gap-3 rounded-lg sticker bg-card p-4">
        <Receipt className="size-5 shrink-0 text-primary" aria-hidden />
        <span className="flex-1">
          <span className="block font-extrabold">{t('costs.title')}</span>
          <span className="block text-sm text-muted-foreground">{t('costs.settingsHint')}</span>
        </span>
        <ChevronRight className="size-5 text-muted-foreground" aria-hidden />
      </Link>

      <button type="button" onClick={() => logout.mutate()} className="flex h-11 items-center gap-2 self-start px-1 font-bold text-destructive">
        <LogOut className="size-5" aria-hidden />
        {t('settings.logout')}
      </button>
    </section>
  )
}
