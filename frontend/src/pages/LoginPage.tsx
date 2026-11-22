import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type Location, Link, Navigate, useLocation } from 'react-router'
import { ApiError } from '@/api/client'
import { useLogin, useMe } from '@/auth/useAuth'
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
    <div className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-4">
      <img src="/logo.svg" alt="" className="mb-6 size-16" />
      <h1 className="text-2xl font-semibold tracking-tight">{t('login.title')}</h1>
      <p className="mt-1 mb-6 text-sm text-muted-foreground">{t('login.subtitle')}</p>
      {state?.notice === 'passwordReset' && (
        <p className="mb-4 rounded-lg border bg-card p-3 text-sm" role="status">
          {t('password.reset.done')}
        </p>
      )}
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('login.email')}
          <input
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="h-11 rounded-md border border-input bg-card px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </label>
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('login.password')}
          <input
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="h-11 rounded-md border border-input bg-card px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </label>
        {error && (
          <p className="text-sm text-destructive" role="alert">
            {error}
          </p>
        )}
        <button
          type="submit"
          disabled={login.isPending}
          className="h-11 rounded-md bg-primary font-medium text-primary-foreground transition-opacity disabled:opacity-60"
        >
          {login.isPending ? t('login.submitting') : t('login.submit')}
        </button>
        <Link to="/forgot-password" className="text-center text-sm font-medium text-primary">
          {t('password.forgot.link')}
        </Link>
      </form>
    </div>
  )
}
