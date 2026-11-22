import { Link2, Sparkles } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { DUPLICATE_LINK, INVALID_LINK, useSaveLink, ValidationError } from '@/api/library'
import { Button, inputClass } from '@/components/ui'
import { cn } from '@/lib/utils'

const canReadClipboard = typeof navigator !== 'undefined' && typeof navigator.clipboard?.readText === 'function'

/**
 * Save a link: one tap pastes what you just copied in Instagram or TikTok and saves it (reading the
 * clipboard needs that tap). Typing or pasting by hand works too. It lands in the inbox right away.
 */
export function SaveLinkForm() {
  const { t } = useTranslation()
  const save = useSaveLink()
  const [url, setUrl] = useState('')

  const submit = (value: string) => {
    const trimmed = value.trim()
    if (!trimmed) return
    save.mutate(trimmed, { onSuccess: () => setUrl('') })
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    submit(url)
  }

  const pasteAndSave = async () => {
    try {
      const text = (await navigator.clipboard.readText()).trim()
      setUrl(text)
      submit(text)
    } catch {
      // Permission denied or nothing to paste: the user can still paste by hand.
    }
  }

  const code = save.error instanceof ValidationError ? save.error.violations[0]?.code : undefined
  const error = save.error
    ? code === DUPLICATE_LINK
      ? t('library.save.duplicate')
      : code === INVALID_LINK || save.error instanceof ValidationError
        ? t('library.save.invalid')
        : t('library.save.error')
    : null

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-2.5">
      {canReadClipboard && (
        <Button className="h-13 w-full text-[17px]" onClick={() => void pasteAndSave()} disabled={save.isPending}>
          <Sparkles className="size-5" aria-hidden />
          {save.isPending ? t('library.save.saving') : t('library.save.pasteAndSave')}
        </Button>
      )}
      <div className="flex gap-2">
        <label className="relative flex-1">
          <span className="sr-only">{t('library.save.label')}</span>
          <Link2 className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <input
            type="text"
            inputMode="url"
            autoComplete="off"
            autoCapitalize="off"
            spellCheck={false}
            enterKeyHint="go"
            placeholder={canReadClipboard ? t('library.save.orPlaceholder') : t('library.save.placeholder')}
            value={url}
            onChange={(e) => {
              setUrl(e.target.value)
              if (save.isError) save.reset()
            }}
            aria-invalid={error ? true : undefined}
            className={cn(inputClass, 'pl-10')}
          />
        </label>
        <Button type="submit" variant={canReadClipboard ? 'secondary' : 'primary'} disabled={save.isPending || !url.trim()}>
          {t('library.save.submit')}
        </Button>
      </div>
      <p className={cn('min-h-5 text-sm', error ? 'text-destructive' : 'text-muted-foreground')} role="status" aria-live="polite">
        {error ?? (save.isSuccess ? t('library.save.saved') : '')}
      </p>
    </form>
  )
}
