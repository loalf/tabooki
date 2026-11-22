import { ChevronRight, LogOut, Receipt } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useLogout, useMe, useUpdateMe } from '@/auth/useAuth'
import { ChangePassword } from '@/components/ChangePassword'
import { PageHeader } from '@/components/PageHeader'
import { ShareSettings } from '@/components/ShareSettings'
import { LOCALES, type Locale } from '@/i18n'
import { cn } from '@/lib/utils'

export function SettingsPage() {
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
    <>
      <PageHeader title={t('settings.title')} />
      <section className="flex flex-col gap-6">
        <div className="rounded-lg border bg-card p-4">
          <p className="font-medium">{me.displayName}</p>
          <p className="text-sm text-muted-foreground">{t('settings.signedInAs', { email: me.email })}</p>
          <p className="mt-2 text-sm text-muted-foreground">
            {t('settings.household')}: {me.householdName}
          </p>
        </div>

        <fieldset className="rounded-lg border bg-card p-4">
          <legend className="px-1 text-sm font-medium">{t('settings.language')}</legend>
          <div className="mt-1 grid grid-cols-2 gap-2">
            {LOCALES.map((locale) => (
              <button
                key={locale}
                type="button"
                onClick={() => changeLocale(locale)}
                aria-pressed={me.locale === locale}
                className={cn(
                  'h-11 rounded-md border font-medium',
                  me.locale === locale ? 'border-primary bg-accent text-accent-foreground' : 'bg-background',
                )}
              >
                {t(`languages.${locale}`)}
              </button>
            ))}
          </div>
        </fieldset>

        <ShareSettings />

        <ChangePassword />

        <Link to="/settings/costs" className="flex items-center gap-3 rounded-lg border bg-card p-4">
          <Receipt className="size-5 shrink-0 text-primary" aria-hidden />
          <span className="flex-1">
            <span className="block font-medium">{t('costs.title')}</span>
            <span className="block text-sm text-muted-foreground">{t('costs.settingsHint')}</span>
          </span>
          <ChevronRight className="size-5 text-muted-foreground" aria-hidden />
        </Link>

        <button
          type="button"
          onClick={() => logout.mutate()}
          className="flex h-11 items-center justify-center gap-2 rounded-md border bg-card font-medium text-destructive"
        >
          <LogOut className="size-5" aria-hidden />
          {t('settings.logout')}
        </button>
      </section>
    </>
  )
}
