import { CircleCheck, CircleAlert, LoaderCircle } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { DUPLICATE_LINK, useSaveLink, ValidationError } from '@/api/library'
import { findSharedUrl } from '@/api/share'

/**
 * Where Android's share sheet lands (share_target in vite.config.ts): /share?url=…&text=…&title=….
 * Saves the link and goes to the library, where it shows up in the inbox being extracted.
 */
export function SharePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const save = useSaveLink()
  const started = useRef(false)
  const url = findSharedUrl(params.get('url'), params.get('text'), params.get('title'))

  useEffect(() => {
    if (!url || started.current) return
    started.current = true
    save.mutate(url, { onSuccess: () => navigate('/', { replace: true }) })
  }, [url, save, navigate])

  const duplicate = save.error instanceof ValidationError && save.error.violations.some((v) => v.code === DUPLICATE_LINK)

  if (!url || (save.isError && !duplicate)) {
    return (
      <Result icon={<CircleAlert className="size-10 text-destructive" aria-hidden />} title={url ? t('share.failed') : t('share.noLink')}>
        {params.get('text') && <p className="line-clamp-4 text-sm text-muted-foreground">{params.get('text')}</p>}
      </Result>
    )
  }
  if (duplicate) {
    return <Result icon={<CircleCheck className="size-10 text-primary" aria-hidden />} title={t('share.duplicate')} />
  }
  return (
    <Result icon={<LoaderCircle className="size-10 animate-spin text-primary" aria-hidden />} title={t('share.saving')}>
      <p className="truncate text-sm text-muted-foreground">{url}</p>
    </Result>
  )
}

function Result({ icon, title, children }: { icon: React.ReactNode; title: string; children?: React.ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-center gap-3 pt-24 text-center" role="status">
      {icon}
      <h1 className="text-lg font-extrabold">{title}</h1>
      <div className="w-full max-w-sm">{children}</div>
      <Link to="/" replace className="mt-2 text-sm font-bold text-primary underline-offset-4 hover:underline">
        {t('share.toLibrary')}
      </Link>
    </div>
  )
}
