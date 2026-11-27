import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type Location, Link, Navigate, useLocation, useSearchParams } from 'react-router'
import { ApiError } from '@/api/client'
import { googleSignInUrl, useAuthOptions, useLogin, useMe } from '@/auth/useAuth'
import { AuthLayout } from '@/components/auth/AuthLayout'
import { authButtonClass, authInputClass, authLinkClass, authNoticeClass, authSecondaryButtonClass } from '@/components/auth/styles'
import { Splash } from '@/components/Splash'

export function LoginPage() {
  const { t, i18n } = useTranslation()
  const { data: me, isPending } = useMe()
  const { data: options, isPending: optionsPending } = useAuthOptions()
  const login = useLogin()
  const state = useLocation().state as { from?: Location; notice?: 'passwordReset' } | null
  const from = state?.from
  const target = from ? `${from.pathname}${from.search}` : '/'
  // Back from Google with a problem (App\Security\GoogleAuthenticator).
  const [params] = useSearchParams()
  const googleError = params.get('error')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  // Wait for the options too, so the Google button doesn't push the form down once it's open.
  if (isPending || optionsPending) return <Splash />
  if (me) return <Navigate to={target} replace />

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    login.mutate({ email, password })
  }

  const error = login.error
    ? login.error instanceof ApiError && login.error.status === 401
      ? t('login.invalid')
      : t('login.error')
    : null
  const googleMessage = googleError === 'google-limit' ? t('login.google.limit') : googleError === 'google' ? t('login.google.error') : null

  return (
    <AuthLayout title={t('login.title')} subtitle={t('login.subtitle')}>
      {state?.notice === 'passwordReset' && (
        <p className={`mb-4 ${authNoticeClass}`} role="status">
          {t('password.reset.done')}
        </p>
      )}
      {options?.google && (
        <>
          {googleMessage && (
            <p className="mb-3 text-sm text-destructive" role="alert">
              {googleMessage}
            </p>
          )}
          <a href={googleSignInUrl(target, i18n.resolvedLanguage ?? 'es')} className={authSecondaryButtonClass}>
            <GoogleLogo />
            {t('login.google.continue')}
          </a>
          <div className="my-5 flex items-center gap-3 text-sm font-bold text-muted-foreground" aria-hidden>
            <span className="h-0.5 flex-1 bg-outline/20" />
            {t('login.or')}
            <span className="h-0.5 flex-1 bg-outline/20" />
          </div>
        </>
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

/** Google's "G", as its sign-in branding guidelines ask for on the button. */
function GoogleLogo() {
  return (
    <svg viewBox="0 0 48 48" className="size-5 shrink-0" aria-hidden>
      <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
      <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
      <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
      <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
    </svg>
  )
}
