import { useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ChevronRight, ClipboardPaste, ExternalLink, LoaderCircle, PenLine, RotateCw, Trash2 } from 'lucide-react'
import { type FormEvent, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { hostOf, isWorking, type Source, useDeleteSource, useExtractSource, useInbox } from '@/api/library'
import { PlatformBadge } from '@/components/library/PlatformBadge'
import { Thumbnail } from '@/components/library/Thumbnail'
import { Button, textareaClass } from '@/components/ui'
import { cn } from '@/lib/utils'

const COLLAPSED = 3

/** Saved links that aren't recipes yet: being extracted, or waiting for the cook. */
export function InboxList({ sources }: { sources: Source[] }) {
  const { t } = useTranslation()
  const [expanded, setExpanded] = useState(false)
  if (!sources.length) return null
  const visible = expanded ? sources : sources.slice(0, COLLAPSED)

  return (
    <section aria-labelledby="inbox-title">
      <h3 id="inbox-title" className="mb-2 text-[15px] font-extrabold">
        {t('library.inbox.title')} <span className="font-normal text-muted-foreground">{sources.length}</span>
      </h3>
      <ul className="flex flex-col divide-y rounded-lg sticker bg-card">
        {visible.map((source) => (
          <SourceRow key={source.id} source={source} />
        ))}
      </ul>
      {sources.length > COLLAPSED && (
        <Button variant="ghost" size="sm" className="mt-1 w-full" onClick={() => setExpanded(!expanded)}>
          {expanded ? t('library.inbox.showLess') : t('library.inbox.showAll', { count: sources.length })}
        </Button>
      )}
    </section>
  )
}

/**
 * The inbox folded into one line on the library: what's being extracted and what needs a hand.
 * Tapping it opens the "add recipe" sheet, where the links are.
 */
export function InboxBanner({ onOpen }: { onOpen: () => void }) {
  const { t } = useTranslation()
  const { data: sources = [] } = useInbox()
  useRefreshRecipesWhenExtracted(sources)
  if (!sources.length) return null

  const working = sources.filter(isWorking).length
  const stuck = sources.filter((s) => s.status === 'needs_input' || s.status === 'failed').length
  const parts = [
    working ? t('library.inbox.bannerWorking', { count: working }) : null,
    stuck ? t('library.inbox.bannerStuck', { count: stuck }) : null,
  ].filter(Boolean)

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-haspopup="dialog"
      className="flex w-full items-center gap-2.5 rounded-md border-2 border-outline bg-accent px-3 py-2.5 text-left text-accent-foreground"
    >
      <span className="flex shrink-0 -space-x-2.5" aria-hidden>
        {sources.slice(0, 2).map((s) => (
          <Thumbnail key={s.id} src={s.thumbnail} className="size-8 rounded-full border-2 border-outline" />
        ))}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] font-bold">
          {parts.length ? parts.join(' · ') : t('library.inbox.bannerSaved', { count: sources.length })}
        </span>
        <span className="block text-xs opacity-80">{t('library.inbox.bannerHint')}</span>
      </span>
      {working > 0 && <LoaderCircle className="size-4 shrink-0 animate-spin text-primary" aria-hidden />}
      <ChevronRight className="size-5 shrink-0" aria-hidden />
    </button>
  )
}

/** A link leaving the inbox usually means it just became a recipe: show it in the grid. */
function useRefreshRecipesWhenExtracted(sources: Source[]) {
  const queryClient = useQueryClient()
  const previous = useRef<Set<string> | null>(null)
  useEffect(() => {
    const ids = new Set(sources.map((s) => s.id!))
    if (previous.current && [...previous.current].some((id) => !ids.has(id))) {
      void queryClient.invalidateQueries({ queryKey: ['recipes'] })
      void queryClient.invalidateQueries({ queryKey: ['recipe-tags'] })
    }
    previous.current = ids
  }, [sources, queryClient])
}

function SourceRow({ source }: { source: Source }) {
  const { t } = useTranslation()
  const remove = useDeleteSource()
  const extract = useExtractSource()
  const [confirming, setConfirming] = useState(false)
  const [pasting, setPasting] = useState(source.status === 'needs_input')
  const [text, setText] = useState('')
  const loadingPreview = !source.metadataFetchedAt
  const working = isWorking(source) || extract.isPending
  const stuck = source.status === 'needs_input' || source.status === 'failed'

  // A second tap within a few seconds confirms the delete.
  useEffect(() => {
    if (!confirming) return
    const timer = setTimeout(() => setConfirming(false), 4000)
    return () => clearTimeout(timer)
  }, [confirming])

  const submitText = (event: FormEvent) => {
    event.preventDefault()
    if (!text.trim()) return
    extract.mutate({ id: source.id!, text: text.trim() }, { onSuccess: () => setText('') })
  }

  return (
    <li className={cn('flex flex-col gap-2 p-3', remove.isPending && 'opacity-50')}>
      <div className="flex gap-3">
        <a href={source.url} target="_blank" rel="noreferrer" className="shrink-0" tabIndex={-1} aria-hidden>
          <Thumbnail src={source.thumbnail} className={cn('size-14 rounded-sm border-2 border-outline', loadingPreview && 'animate-pulse')} />
        </a>
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <a href={source.url} target="_blank" rel="noreferrer" className="group flex min-w-0 items-start gap-1">
            <span className={cn('line-clamp-2 font-bold group-hover:underline', !source.title && 'text-muted-foreground')}>
              {source.title ?? (loadingPreview ? t('library.inbox.loadingPreview') : hostOf(source.url))}
            </span>
            <ExternalLink className="mt-1 size-3.5 shrink-0 text-muted-foreground" aria-label={t('library.openOriginal')} />
          </a>
          <PlatformBadge platform={source.platform} url={source.url} />
          <StatusLine source={source} working={working} />
        </div>
      </div>

      {stuck && source.statusMessage && (
        <p className="flex gap-2 rounded-md bg-muted px-3 py-2 text-sm">
          <AlertCircle className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
          {source.statusMessage}
        </p>
      )}

      {pasting && !working && (
        <form onSubmit={submitText} className="flex flex-col gap-2">
          <label className="text-sm font-medium" htmlFor={`paste-${source.id}`}>
            {t('library.inbox.pasteLabel')}
          </label>
          <textarea
            id={`paste-${source.id}`}
            rows={5}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={t('library.inbox.pastePlaceholder')}
            className={textareaClass}
          />
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={!text.trim() || extract.isPending}>
              {t('library.inbox.extractText')}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setPasting(false)}>
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      )}

      <div className="flex flex-wrap gap-2 pl-[4.25rem]">
        {stuck && !pasting && (
          <Button variant="secondary" size="sm" onClick={() => setPasting(true)}>
            <ClipboardPaste className="size-4" aria-hidden />
            {t('library.inbox.pasteText')}
          </Button>
        )}
        {(source.status === 'failed' || (source.status === 'pending' && !working)) && (
          <Button variant="secondary" size="sm" disabled={extract.isPending} onClick={() => extract.mutate({ id: source.id! })}>
            <RotateCw className="size-4" aria-hidden />
            {source.status === 'failed' ? t('library.inbox.retry') : t('library.inbox.extract')}
          </Button>
        )}
        {!working && (
          <Link
            to={`/recipes/new?source=${source.id}`}
            className="inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-muted"
          >
            <PenLine className="size-4" aria-hidden />
            {t('library.inbox.writeRecipe')}
          </Link>
        )}
        <Button
          variant={confirming ? 'danger' : 'ghost'}
          size="sm"
          className="ml-auto"
          disabled={remove.isPending}
          onClick={() => (confirming ? remove.mutate(source.id!) : setConfirming(true))}
          aria-label={confirming ? undefined : t('library.inbox.delete')}
        >
          <Trash2 className="size-4" aria-hidden />
          {confirming && t('common.confirmDelete')}
        </Button>
      </div>
    </li>
  )
}

function StatusLine({ source, working }: { source: Source; working: boolean }) {
  const { t } = useTranslation()
  if (working) {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm font-bold text-primary" role="status">
        <LoaderCircle className="size-4 animate-spin" aria-hidden />
        {source.status === 'extracting' ? t('library.inbox.extracting') : t('library.inbox.queued')}
      </span>
    )
  }
  if (source.status === 'needs_input') {
    return <span className="text-sm font-bold text-destructive">{t('library.inbox.needsInput')}</span>
  }
  if (source.status === 'failed') {
    return <span className="text-sm font-bold text-destructive">{t('library.inbox.failed')}</span>
  }
  return null
}
