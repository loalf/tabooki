import type { ReactNode } from 'react'
import { Eggs } from '@/components/brand'
import { cn } from '@/lib/utils'

/** Page titles are the one place (besides the wordmark) that uses the display face. */
export const pageTitleClass = 'font-display text-[2.25rem] leading-[1.1] font-normal text-balance text-brand'

export function PageHeader({ title, className }: { title: string; className?: string }) {
  return <h1 className={cn('mb-4', pageTitleClass, className)}>{title}</h1>
}

/** Nothing here yet (or nothing matches): the eggs, a line of explanation and what to do next. */
export function EmptyState({ title, children, action }: { title?: string; children: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border-2 border-dashed border-muted-foreground/60 px-6 py-9 text-center">
      <Eggs eggClassName="size-14" className="mb-1" />
      {title && <p className="text-xl font-extrabold text-balance">{title}</p>}
      <div className="max-w-xs text-[15px] leading-relaxed text-balance text-muted-foreground">{children}</div>
      {action}
    </div>
  )
}
