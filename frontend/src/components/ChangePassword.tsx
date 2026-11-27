import { KeyRound } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MIN_PASSWORD_LENGTH, useChangePassword } from '@/api/password'
import { useMe } from '@/auth/useAuth'
import { Button, Field, inputClass } from '@/components/ui'

/** Settings → Password: change it, or add one after signing up with Google (no current password then). */
export function ChangePassword() {
  const { t } = useTranslation()
  const change = useChangePassword()
  const { data: me } = useMe()
  const adding = me?.hasPassword === false
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [repeat, setRepeat] = useState('')
  const [mismatch, setMismatch] = useState(false)

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (next !== repeat) return setMismatch(true)
    setMismatch(false)
    change.mutate(
      { currentPassword: current, newPassword: next },
      {
        onSuccess: () => {
          setCurrent('')
          setNext('')
          setRepeat('')
          setOpen(false)
        },
      },
    )
  }

  const fields = change.error?.fields ?? []
  const error = mismatch
    ? t('password.mismatch')
    : change.error
      ? change.error.status === 429
        ? t('password.tooMany')
        : fields.includes('currentPassword')
          ? t('password.change.wrongCurrent')
          : fields.includes('newPassword')
            ? next === current
              ? t('password.change.same')
              : t('password.tooShort', { count: MIN_PASSWORD_LENGTH })
            : t('password.error')
      : null

  return (
    <section className="rounded-lg sticker bg-card p-4" aria-labelledby="password-title">
      <div className="flex items-center gap-3">
        <KeyRound className="size-5 shrink-0 text-primary" aria-hidden />
        <div className="flex-1">
          <h2 id="password-title" className="font-extrabold">
            {t('password.change.title')}
          </h2>
          {change.isSuccess && !open ? (
            <p className="text-sm text-primary" role="status">
              {t('password.change.done')}
            </p>
          ) : (
            adding && <p className="text-sm text-muted-foreground">{t('password.add.hint')}</p>
          )}
        </div>
        {!open && (
          <Button variant="secondary" size="sm" onClick={() => (change.reset(), setOpen(true))}>
            {adding ? t('password.add.open') : t('password.change.open')}
          </Button>
        )}
      </div>
      {open && (
        <form onSubmit={onSubmit} className="mt-4 flex flex-col gap-3">
          {/* Lets password managers file the new password under the right account. */}
          <input type="email" autoComplete="username" value={me?.email ?? ''} readOnly hidden />
          {!adding && (
            <Field label={t('password.current')}>
              <input type="password" autoComplete="current-password" required value={current} onChange={(e) => setCurrent(e.target.value)} className={inputClass} />
            </Field>
          )}
          <Field label={t('password.new')} hint={t('password.hint', { count: MIN_PASSWORD_LENGTH })}>
            <input type="password" autoComplete="new-password" required minLength={MIN_PASSWORD_LENGTH} value={next} onChange={(e) => setNext(e.target.value)} className={inputClass} />
          </Field>
          <Field label={t('password.repeat')}>
            <input type="password" autoComplete="new-password" required value={repeat} onChange={(e) => setRepeat(e.target.value)} className={inputClass} />
          </Field>
          <p className="text-xs text-muted-foreground">{t('password.change.otherDevices')}</p>
          {error && (
            <p className="text-sm text-destructive" role="alert">
              {error}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={change.isPending}>
              {change.isPending ? t('password.saving') : adding ? t('password.add.submit') : t('password.change.submit')}
            </Button>
            <Button variant="ghost" onClick={() => (setOpen(false), setMismatch(false), change.reset())}>
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      )}
    </section>
  )
}
