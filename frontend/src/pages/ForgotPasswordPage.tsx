import { MailCheck } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useForgotPassword } from '@/api/password'
import { AuthLayout, authButtonClass, authInputClass } from '@/components/auth/AuthLayout'

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const forgot = useForgotPassword()
  const [email, setEmail] = useState('')

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    forgot.mutate(email.trim())
  }

  return (
    <AuthLayout title={t('password.forgot.title')} subtitle={forgot.isSuccess ? undefined : t('password.forgot.subtitle')}>
      {forgot.isSuccess ? (
        <div className="flex flex-col gap-4">
          <p className="flex gap-3 rounded-lg border bg-card p-4 text-sm leading-relaxed" role="status">
            <MailCheck className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden />
            {t('password.forgot.sent', { email: email.trim() })}
          </p>
          <Link to="/login" className="text-center text-sm font-medium text-primary">
            {t('password.backToLogin')}
          </Link>
        </div>
      ) : (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <label className="flex flex-col gap-1.5 text-sm font-medium">
            {t('login.email')}
            <input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className={authInputClass} />
          </label>
          {forgot.isError && (
            <p className="text-sm text-destructive" role="alert">
              {forgot.error.status === 429 ? t('password.tooMany') : t('password.error')}
            </p>
          )}
          <button type="submit" disabled={forgot.isPending} className={authButtonClass}>
            {forgot.isPending ? t('password.sending') : t('password.forgot.submit')}
          </button>
          <Link to="/login" className="text-center text-sm font-medium text-primary">
            {t('password.backToLogin')}
          </Link>
        </form>
      )}
    </AuthLayout>
  )
}
