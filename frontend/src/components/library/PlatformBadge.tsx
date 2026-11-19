import { Camera, Globe, Music2, Play } from 'lucide-react'
import { platformLabel, type Platform } from '@/api/library'
import { cn } from '@/lib/utils'

const icons = { youtube: Play, instagram: Camera, tiktok: Music2, web: Globe } as const

export function PlatformBadge({ platform, url, className }: { platform?: Platform; url?: string; className?: string }) {
  const Icon = icons[platform ?? 'web']
  return (
    <span className={cn('inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground', className)}>
      <Icon className="size-3.5 shrink-0" aria-hidden />
      <span className="truncate">{platformLabel(platform, url)}</span>
    </span>
  )
}
