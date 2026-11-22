import { Check, Copy, Smartphone, Trash2 } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { useCreateShareToken, useDeleteShareToken, useShareTokens } from '@/api/share'
import { Button, inputClass } from '@/components/ui'

/** Saving links from the phone: Android uses the installed app's share target; iPhone a Shortcut with a token. */
export function ShareSettings() {
  const { t, i18n } = useTranslation()
  const { data: tokens = [] } = useShareTokens()
  const create = useCreateShareToken()
  const remove = useDeleteShareToken()
  const [name, setName] = useState('iPhone')
  const [secret, setSecret] = useState<string | null>(null)
  const date = new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium' })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!name.trim()) return
    create.mutate(name.trim(), { onSuccess: setSecret })
  }

  return (
    <section className="rounded-lg sticker bg-card p-4" aria-labelledby="share-title">
      <h2 id="share-title" className="flex items-center gap-2 font-extrabold">
        <Smartphone className="size-5 text-primary" aria-hidden />
        {t('settings.share.title')}
      </h2>
      <p className="mt-2 text-sm text-muted-foreground">{t('settings.share.android')}</p>

      <h3 className="mt-4 text-sm font-extrabold">{t('settings.share.iphone')}</h3>
      <p className="mt-1 text-sm text-muted-foreground">{t('settings.share.iphoneIntro')}</p>

      {tokens.length > 0 && (
        <ul className="mt-3 flex flex-col divide-y rounded-md border-2 border-outline">
          {tokens.map((token) => (
            <li key={token.id} className="flex items-center gap-2 px-3 py-2 text-sm">
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{token.name}</p>
                <p className="text-muted-foreground">
                  {token.lastUsedAt
                    ? t('settings.share.lastUsed', { date: date.format(new Date(token.lastUsedAt)) })
                    : t('settings.share.neverUsed')}
                </p>
              </div>
              <Button
                variant="ghost"
                size="icon"
                aria-label={t('settings.share.revoke', { name: token.name })}
                disabled={remove.isPending}
                onClick={() => remove.mutate(token.id!)}
              >
                <Trash2 className="size-4" aria-hidden />
              </Button>
            </li>
          ))}
        </ul>
      )}

      {secret ? (
        <ShortcutSteps secret={secret} onDone={() => setSecret(null)} />
      ) : (
        <form onSubmit={submit} className="mt-3 flex gap-2">
          <label className="sr-only" htmlFor="share-token-name">
            {t('settings.share.deviceName')}
          </label>
          <input
            id="share-token-name"
            value={name}
            maxLength={60}
            onChange={(e) => setName(e.target.value)}
            className={inputClass}
            placeholder={t('settings.share.deviceName')}
          />
          <Button type="submit" disabled={!name.trim() || create.isPending}>
            {t('settings.share.create')}
          </Button>
        </form>
      )}
    </section>
  )
}

function ShortcutSteps({ secret, onDone }: { secret: string; onDone: () => void }) {
  const { t } = useTranslation()
  const endpoint = `${window.location.origin}/api/share`
  const steps = ['open', 'shareSheet', 'getContents', 'headers', 'body', 'notify', 'name'] as const

  return (
    <div className="mt-3 flex flex-col gap-3 rounded-md bg-muted p-3 text-sm">
      <p className="font-medium">{t('settings.share.copyNow')}</p>
      <CopyField label={t('settings.share.token')} value={`Bearer ${secret}`} />
      <CopyField label={t('settings.share.address')} value={endpoint} />
      <ol className="flex list-decimal flex-col gap-1.5 pl-5">
        {steps.map((step) => (
          <li key={step}>
            <Trans i18nKey={`settings.share.steps.${step}`} components={{ b: <strong />, code: <code className="rounded bg-card px-1" /> }} />
          </li>
        ))}
      </ol>
      <Button variant="secondary" size="sm" className="self-start" onClick={onDone}>
        {t('settings.share.done')}
      </Button>
    </div>
  )
}

function CopyField({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  const copy = () =>
    void navigator.clipboard.writeText(value).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })

  return (
    <div>
      <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
      <div className="flex items-center gap-2">
        <code className="min-w-0 flex-1 truncate rounded bg-card px-2 py-2 text-xs">{value}</code>
        <Button variant="secondary" size="icon" onClick={copy} aria-label={t('settings.share.copy', { what: label })}>
          {copied ? <Check className="size-4" aria-hidden /> : <Copy className="size-4" aria-hidden />}
        </Button>
      </div>
    </div>
  )
}
