import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useMe } from '@/auth/useAuth'

/** Applies the signed-in user's saved language (it follows them across devices). */
export function LocaleSync() {
  const { i18n } = useTranslation()
  const { data: me } = useMe()

  useEffect(() => {
    if (me?.locale && me.locale !== i18n.resolvedLanguage) void i18n.changeLanguage(me.locale)
  }, [me?.locale, i18n])

  return null
}
