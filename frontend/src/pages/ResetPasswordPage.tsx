import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { MIN_PASSWORD_LENGTH, useResetPassword } from '@/api/password'
import { AuthLayout, authButtonClass, authInputClass } from '@/components/auth/AuthLayout'
import { useLogout, useMe } from '@/auth/useAuth'

/** /reset-password?token=…, from the emailed link. */
export function ResetPasswordPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const reset = useResetPassword()
  const logout = useLogout()
  const { data: me } = useMe()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  const [repeat, setRepeat] = useState('')
  const [mismatch, setMismatch] = useState(false)

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (password !== repeat) return setMismatch(true)
    setMismatch(false)
    reset.mutate(
      { token, password },
      {
        // Every device is signed out now, this one included.
        onSuccess: () =>
          void (me ? logout.mutateAsync() : Promise.resolve()).then(() => navigate('/login', { replace: true, state: { notice: 'passwordReset' } })),
      },
    )
  }

  const badLink = !token || (reset.error?.status === 422 && reset.error.fields.includes('token'))
  if (badLink) {
    return (
      <AuthLayout title={t('password.reset.badLinkTitle')} subtitle={t('password.reset.badLink')}>
        <Link to="/forgot-password" className={`${authButtonClass} grid place-items-center`}>
          {t('password.reset.newLink')}
        </Link>
      </AuthLayout>
    )
  }

  const error = mismatch
    ? t('password.mismatch')
    : reset.error
      ? reset.error.status === 429
        ? t('password.tooMany')
        : reset.error.fields.includes('password')
          ? t('password.tooShort', { count: MIN_PASSWORD_LENGTH })
          : t('password.error')
      : null

  return (
    <AuthLayout title={t('password.reset.title')} subtitle={t('password.reset.subtitle')}>
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('password.new')}
          <input
            type="password"
            autoComplete="new-password"
            required
            minLength={MIN_PASSWORD_LENGTH}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className={authInputClass}
          />
          <span className="text-xs font-normal text-muted-foreground">{t('password.hint', { count: MIN_PASSWORD_LENGTH })}</span>
        </label>
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('password.repeat')}
          <input type="password" autoComplete="new-password" required value={repeat} onChange={(e) => setRepeat(e.target.value)} className={authInputClass} />
        </label>
        {error && (
          <p className="text-sm text-destructive" role="alert">
            {error}
          </p>
        )}
        <button type="submit" disabled={reset.isPending} className={authButtonClass}>
          {reset.isPending ? t('password.saving') : t('password.reset.submit')}
        </button>
      </form>
    </AuthLayout>
  )
}
