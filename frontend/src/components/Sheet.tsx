import { type ReactNode, type TouchEvent, useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/utils'

/**
 * A bottom sheet: secondary actions and short flows open over the page instead of replacing it,
 * so the context stays visible behind. Closes with the scrim, Escape or a swipe down on its top.
 */
export function Sheet({
  title,
  onClose,
  children,
  className,
  hideTitle = false,
}: {
  title: string
  onClose: () => void
  children: ReactNode
  className?: string
  /** The title is still read out, but the content shows its own heading. */
  hideTitle?: boolean
}) {
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)
  const swipe = useRef<number | null>(null)
  const close = useRef(onClose)
  useEffect(() => {
    close.current = onClose
  })

  // Escape closes it, the page behind doesn't scroll, and focus moves into it (and back after).
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && close.current()
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    panel.current?.focus()
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
      previous?.focus?.()
    }
  }, [])

  const onTouchStart = (event: TouchEvent) => {
    swipe.current = (panel.current?.scrollTop ?? 0) <= 0 ? event.touches[0].clientY : null
  }
  const onTouchEnd = (event: TouchEvent) => {
    const from = swipe.current
    swipe.current = null
    if (from !== null && event.changedTouches[0].clientY - from > 90) onClose()
  }

  return createPortal(
    <div className="fixed inset-0 z-50 flex flex-col justify-end">
      <div className="sheet-scrim absolute inset-0 bg-[#1f140c]/60" onClick={onClose} aria-hidden />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        onTouchStart={onTouchStart}
        onTouchEnd={onTouchEnd}
        className={cn(
          'sheet-panel relative mx-auto max-h-[92dvh] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-t-[24px] border-t-2 border-outline bg-background px-4 pt-2.5 pb-[max(1.5rem,env(safe-area-inset-bottom))] outline-none sm:border-x-2',
          className,
        )}
      >
        <div className="mb-3 flex justify-center" aria-hidden>
          <span className="h-[5px] w-10 rounded-full bg-border" />
        </div>
        <h2 id={titleId} className={cn('mb-4 text-[1.375rem] leading-tight font-extrabold', hideTitle && 'sr-only')}>
          {title}
        </h2>
        {children}
      </div>
    </div>,
    document.body,
  )
}

/** A tappable line in a sheet: icon, title, a hint, and a chevron. Render as a link or a button. */
export function SheetRowContent({ icon, title, hint, trailing }: { icon: ReactNode; title: string; hint?: string; trailing?: ReactNode }) {
  return (
    <>
      <span className="grid size-9 shrink-0 place-items-center rounded-[10px] border-2 border-outline bg-muted [&>svg]:size-[18px]">{icon}</span>
      <span className="min-w-0 flex-1">
        <span className="block font-bold">{title}</span>
        {hint && <span className="block text-xs leading-snug text-muted-foreground">{hint}</span>}
      </span>
      {trailing}
    </>
  )
}

export const sheetRowClass = 'flex w-full items-center gap-3 px-1 py-3 text-left disabled:opacity-50'
