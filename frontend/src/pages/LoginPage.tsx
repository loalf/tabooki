import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type Location, Link, Navigate, useLocation } from 'react-router'
import { ApiError } from '@/api/client'
import { useLogin, useMe } from '@/auth/useAuth'
import { AuthLayout } from '@/components/auth/AuthLayout'
import { authButtonClass, authInputClass, authLinkClass, authNoticeClass } from '@/components/auth/styles'
import { Splash } from '@/components/Splash'

export function LoginPage() {
  const { t } = useTranslation()
  const { data: me, isPending } = useMe()
  const login = useLogin()
  const state = useLocation().state as { from?: Location; notice?: 'passwordReset' } | null
  const from = state?.from
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  if (isPending) return <Splash />
  if (me) return <Navigate to={from ? `${from.pathname}${from.search}` : '/'} replace />

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    login.mutate({ email, password })
  }

  const error = login.error
    ? login.error instanceof ApiError && login.error.status === 401
      ? t('login.invalid')
      : t('login.error')
    : null

  return (
    <AuthLayout title={t('login.title')} subtitle={t('login.subtitle')}>
      {state?.notice === 'passwordReset' && (
        <p className={`mb-4 ${authNoticeClass}`} role="status">
          {t('password.reset.done')}
        </p>
      )}
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <label className="flex flex-col gap-1.5 text-sm font-bold">
          {t('login.email')}
          <input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className={authInputClass} />
        </label>
        <label className="flex flex-col gap-1.5 text-sm font-bold">
          {t('login.password')}
          <input
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className={authInputClass}
          />
        </label>
        {error && (
          <p className="text-sm text-destructive" role="alert">
            {error}
          </p>
        )}
        <button type="submit" disabled={login.isPending} className={`mt-1 ${authButtonClass}`}>
          {login.isPending ? t('login.submitting') : t('login.submit')}
        </button>
        <Link to="/forgot-password" className={authLinkClass}>
          {t('password.forgot.link')}
        </Link>
      </form>
    </AuthLayout>
  )
}
