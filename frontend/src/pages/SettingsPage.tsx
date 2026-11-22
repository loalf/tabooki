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
        <div className="rounded-lg sticker bg-card p-4">
          <p className="font-extrabold">{me.displayName}</p>
          <p className="text-sm text-muted-foreground">{t('settings.signedInAs', { email: me.email })}</p>
          <p className="mt-2 text-sm text-muted-foreground">
            {t('settings.household')}: {me.householdName}
          </p>
        </div>

        <fieldset className="rounded-lg sticker bg-card p-4">
          <legend className="float-left mb-2.5 w-full font-extrabold">{t('settings.language')}</legend>
          <div className="clear-both grid grid-cols-2 gap-2">
            {LOCALES.map((locale) => (
              <button
                key={locale}
                type="button"
                onClick={() => changeLocale(locale)}
                aria-pressed={me.locale === locale}
                className={cn(
                  'h-11 rounded-sm border-2 border-outline',
                  me.locale === locale ? 'bg-highlight font-extrabold text-highlight-foreground' : 'bg-card font-semibold',
                )}
              >
                {t(`languages.${locale}`)}
              </button>
            ))}
          </div>
        </fieldset>

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

        <button
          type="button"
          onClick={() => logout.mutate()}
          className="flex h-12 items-center justify-center gap-2 rounded-md border-2 border-destructive bg-card font-bold text-destructive"
        >
          <LogOut className="size-5" aria-hidden />
          {t('settings.logout')}
        </button>
      </section>
    </>
  )
}
